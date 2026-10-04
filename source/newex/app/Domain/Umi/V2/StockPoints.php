<?php

namespace App\Domain\Umi\V2;

use DomainException;
use InvalidArgumentException;

/** Internal display-only points; no share quantity or securities position exists here. */
final class StockPoints
{
    private string $total = '0';
    private array $grants = [];
    private array $burnSources = [];
    private array $reversals = [];
    private array $entries = [];

    private function __construct(public readonly string $accountId, public readonly string $programId) {}

    public static function empty(string $accountId, string $programId): self
    {
        self::id($accountId);
        self::id($programId, 80);
        return new self($accountId, $programId);
    }

    public static function restore(array $snapshot): self
    {
        $book = self::empty((string) ($snapshot['account_id'] ?? ''), (string) ($snapshot['program_id'] ?? ''));
        foreach (($snapshot['entries'] ?? []) as $saved) {
            if (!is_array($saved)) {
                throw new DomainException('Invalid persisted points entry');
            }
            [$book, $regenerated] = match ($saved['type'] ?? null) {
                'internal_stock_fund_points' => $book->creditFromBurn(
                    $saved['id'],
                    ['id' => $saved['source_burn_id'], 'account_id' => $book->accountId,
                        'purpose' => $saved['source_purpose'],
                        'withdrawal_id' => $saved['source_withdrawal_id'],
                        'finality_status' => $saved['source_finality_status'],
                        'burned_umi' => $saved['burned_umi'],
                        'confirmation_ref' => $saved['burn_confirmation_ref']],
                    $saved['points_per_burned_umi'],
                    $saved['rule_id'],
                ),
                'internal_stock_fund_points_reversal' => $book->reverseGrant(
                    $saved['id'], $saved['original_grant_id'], $saved['reversal_source_ref'], $saved['reason'],
                ),
                default => throw new DomainException('Unknown persisted points entry'),
            };
            if (!Payload::same($regenerated, $saved)) {
                throw new DomainException('Persisted points entry does not match replay');
            }
        }
        if (!Payload::same($book->snapshot(), $snapshot)) {
            throw new DomainException('Persisted points summary does not match replay');
        }
        return $book;
    }

    /**
     * The adapter must verify the chain receipt and withdrawal relationship.
     * Only a final withdrawal burn may create visible internal points; an
     * activation burn or a pending burn cannot be credited.
     *
     * @param array{id:string, account_id:string, purpose:string, withdrawal_id:string, finality_status:string, burned_umi:string, confirmation_ref:string} $burn
     * @return array{0:self, 1:array}
     */
    public function creditFromBurn(
        string $grantId,
        array $burn,
        mixed $pointsPerBurnedUmi,
        string $ruleId,
    ): array {
        self::id($grantId);
        self::id($ruleId);
        $burnId = (string) ($burn['id'] ?? '');
        self::id($burnId);
        if (($burn['account_id'] ?? null) !== $this->accountId) {
            throw new DomainException('Burn source belongs to a different account');
        }
        if (($burn['purpose'] ?? null) !== 'withdrawal' || ($burn['cycle_id'] ?? null) !== null) {
            throw new DomainException('Stock points require an exit withdrawal burn');
        }
        $withdrawalId = (string) ($burn['withdrawal_id'] ?? '');
        if (!preg_match('/^[1-9][0-9]*$/D', $withdrawalId)) {
            throw new DomainException('Stock points require a withdrawal source ID');
        }
        if (($burn['finality_status'] ?? null) !== 'final') {
            throw new DomainException('Withdrawal burn is not final');
        }
        $confirmation = trim((string) ($burn['confirmation_ref'] ?? ''));
        if ($confirmation === '' || strlen($confirmation) > 160) {
            throw new InvalidArgumentException('Confirmed burn reference is required');
        }
        $burnedAmount = Decimal::amount($burn['burned_umi'] ?? null, true);
        $ratio = Decimal::amount($pointsPerBurnedUmi, true);
        $input = [$this->programId, $burnId, 'withdrawal', $withdrawalId, 'final', $burnedAmount, $confirmation, $ratio, $ruleId];
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        if (isset($this->entries[$grantId])) {
            $existing = $this->entries[$grantId];
            if ($existing['fingerprint'] !== $fingerprint) {
                throw new DomainException('Grant ID was reused with different input');
            }
            if ($existing['type'] !== 'internal_stock_fund_points') {
                throw new DomainException('Grant ID belongs to a reversal');
            }
            return [$this, $existing];
        }
        if (isset($this->burnSources[$burnId])) {
            throw new DomainException('Burn source already has a points grant');
        }

        $points = Decimal::mul($burnedAmount, $ratio);
        if (Decimal::cmp($points, '0') <= 0) {
            throw new DomainException('Burn produces no displayable points');
        }
        $next = clone $this;
        $next->total = Decimal::add($this->total, $points);
        $event = [
            'id' => $grantId, 'type' => 'internal_stock_fund_points',
            'account_id' => $this->accountId, 'program_id' => $this->programId,
            'source_burn_id' => $burnId, 'burn_confirmation_ref' => $confirmation,
            'source_purpose' => 'withdrawal', 'source_withdrawal_id' => $withdrawalId,
            'source_finality_status' => 'final',
            'burned_umi' => $burnedAmount, 'points_per_burned_umi' => $ratio,
            'points' => $points, 'points_after' => $next->total,
            'rule_id' => $ruleId, 'fingerprint' => $fingerprint,
        ];
        $next->grants[$grantId] = $event;
        $next->burnSources[$burnId] = $grantId;
        $next->entries[$grantId] = $event;
        return [$next, $event];
    }

    /** Append one negative correction for a previously credited burn, never delete it. */
    public function reverseGrant(string $reversalId, string $originalGrantId, string $sourceRef, string $reason): array
    {
        self::id($reversalId);
        self::id($originalGrantId);
        self::id($sourceRef, 160);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('A reversal reason is required');
        }
        $fingerprint = hash('sha256', json_encode(
            [$this->programId, $originalGrantId, $sourceRef, $reason], JSON_THROW_ON_ERROR,
        ));
        if (isset($this->entries[$reversalId])) {
            $existing = $this->entries[$reversalId];
            if ($existing['type'] !== 'internal_stock_fund_points_reversal' || $existing['fingerprint'] !== $fingerprint) {
                throw new DomainException('Reversal ID was reused with different input');
            }
            return [$this, $existing];
        }
        $original = $this->grants[$originalGrantId] ?? null;
        if ($original === null) {
            throw new DomainException('Original points grant does not exist');
        }
        if (isset($this->reversals[$originalGrantId])) {
            throw new DomainException('Original points grant was already reversed');
        }
        if (Decimal::cmp($this->total, $original['points']) < 0) {
            throw new DomainException('Points reversal would make the balance negative');
        }
        $next = clone $this;
        $next->total = Decimal::sub($this->total, $original['points']);
        $event = [
            'id' => $reversalId, 'type' => 'internal_stock_fund_points_reversal',
            'account_id' => $this->accountId, 'program_id' => $this->programId,
            'original_grant_id' => $originalGrantId,
            'source_burn_id' => $original['source_burn_id'],
            'reversal_source_ref' => $sourceRef, 'reason' => $reason,
            'delta_points' => Decimal::sub('0', $original['points']),
            'points_after' => $next->total, 'fingerprint' => $fingerprint,
        ];
        $next->reversals[$originalGrantId] = $reversalId;
        $next->entries[$reversalId] = $event;
        return [$next, $event];
    }

    public function total(): string { return $this->total; }

    public function snapshot(): array
    {
        return [
            'account_id' => $this->accountId,
            'program_id' => $this->programId,
            'display_name' => '股票基金积分',
            'points' => $this->total,
            'grants' => array_values($this->grants),
            'reversals' => array_values(array_filter($this->entries,
                static fn (array $entry): bool => $entry['type'] === 'internal_stock_fund_points_reversal')),
            'entries' => array_values($this->entries),
        ];
    }

    private static function id(string $value, int $maxLength = 120): void
    {
        if ($value === '' || strlen($value) > $maxLength || !preg_match('/^[A-Za-z0-9:_-]+$/D', $value)) {
            throw new InvalidArgumentException('Invalid ID');
        }
    }
}
