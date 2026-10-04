<?php

namespace App\Domain\Umi\V2;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * Pure UMI v2 cycle calculator. Its caller must persist the returned snapshot
 * and events atomically; this class never touches legacy accounts or wallets.
 */
final class Cycle
{
    public const STATIC = 'STATIC';
    public const TEAM_ACCELERATION = 'TEAM_ACCELERATION';
    public const DIRECT_REFERRAL = 'DIRECT_REFERRAL';

    private string $used = '0';
    private string $bonusQuota = '0';
    private string $withdrawable = '0';
    private array $pockets = ['static' => '0', 'team' => '0', 'referral' => '0'];
    private array $events = [];
    private array $sourceEvents = [];
    private array $staticDays = [];

    private function __construct(
        public readonly string $id,
        public readonly string $accountId,
        public readonly string $principal,
        public readonly string $priceUsdt,
        public readonly string $valueUsdt,
        public readonly int $multiple,
        public readonly string $quota,
        public readonly string $openingRuleId,
    ) {}

    public static function open(
        string $cycleId,
        string $accountId,
        mixed $principal,
        mixed $priceUsdt,
        string $openingRuleId,
    ): self {
        self::id($cycleId);
        self::id($accountId);
        self::id($openingRuleId);
        $principal = Decimal::amount($principal, true);
        $priceUsdt = Decimal::amount($priceUsdt, true);
        $value = Decimal::mul($principal, $priceUsdt);
        $multiple = TierPolicy::multipleForUsdValue($value);

        return new self(
            $cycleId,
            $accountId,
            $principal,
            $priceUsdt,
            $value,
            $multiple,
            Decimal::mul($principal, (string) $multiple),
            $openingRuleId,
        );
    }

    /** Rebuild a persisted cycle by replaying every event and checking its summary. */
    public static function restore(array $snapshot): self
    {
        $cycle = self::open(
            (string) ($snapshot['cycle_id'] ?? ''),
            (string) ($snapshot['account_id'] ?? ''),
            (string) ($snapshot['principal'] ?? ''),
            (string) ($snapshot['price_usdt'] ?? ''),
            (string) ($snapshot['opening_rule_id'] ?? ''),
        );
        foreach (($snapshot['events'] ?? []) as $saved) {
            if (!is_array($saved)) {
                throw new DomainException('Invalid persisted cycle event');
            }
            $next = match ($saved['type'] ?? null) {
                'release' => match ($saved['kind'] ?? null) {
                    self::STATIC => $cycle->releaseStatic($saved['id'], $saved['day'], $saved['rate'], $saved['rule_id']),
                    self::TEAM_ACCELERATION => $cycle->releaseTeam($saved['id'], $saved['day'], $saved['source_account_id'], $saved['source_event_id'], $saved['base'], $saved['rate'], $saved['rule_id']),
                    self::DIRECT_REFERRAL => $cycle->releaseDirectReferral($saved['id'], $saved['day'], $saved['source_account_id'], $saved['source_event_id'], $saved['base'], $saved['rate'], $saved['rule_id']),
                    default => throw new DomainException('Unknown persisted release kind'),
                },
                'transfer' => $cycle->transferToWithdrawable($saved['id'], $saved['kind'], $saved['amount']),
                'quota_bonus' => $cycle->grantReferralQuota($saved['id'],
                    $saved['source_account_id'], $saved['source_event_id'],
                    $saved['amount'], $saved['rule_id']),
                default => throw new DomainException('Unknown persisted cycle event'),
            };
            [$cycle, $regenerated] = $next;
            if (!Payload::same($regenerated, $saved)) {
                throw new DomainException('Persisted cycle event does not match replay');
            }
        }
        if (!Payload::same($cycle->snapshot(), $snapshot)) {
            throw new DomainException('Persisted cycle summary does not match replay');
        }
        return $cycle;
    }

    /** Static yield is principal × that day's versioned rate, never quota × rate. */
    public function releaseStatic(string $eventId, string $day, mixed $rate, string $ruleId): array
    {
        return $this->release($eventId, self::STATIC, $day, $this->principal, $rate, $ruleId, null, null);
    }

    /** The caller supplies a verified descendant's static release event and grade-difference rate. */
    public function releaseTeam(
        string $eventId,
        string $day,
        string $sourceAccountId,
        string $sourceEventId,
        mixed $base,
        mixed $differentialRate,
        string $ruleId,
    ): array {
        return $this->release($eventId, self::TEAM_ACCELERATION, $day, $base, $differentialRate, $ruleId, $sourceAccountId, $sourceEventId);
    }

    /** A referral release spends quota; funded callers may grant extra quota first. */
    public function releaseDirectReferral(
        string $eventId,
        string $day,
        string $sourceAccountId,
        string $sourcePurchaseId,
        mixed $base,
        mixed $rate,
        string $ruleId,
    ): array {
        return $this->release($eventId, self::DIRECT_REFERRAL, $day, $base, $rate, $ruleId, $sourceAccountId, $sourcePurchaseId);
    }

    private function release(
        string $eventId,
        string $kind,
        string $day,
        mixed $base,
        mixed $rate,
        string $ruleId,
        ?string $sourceAccountId,
        ?string $sourceEventId,
    ): array {
        self::id($eventId);
        self::day($day);
        self::id($ruleId);
        $base = Decimal::amount($base);
        $rate = Decimal::rate($rate);
        if ($kind !== self::STATIC) {
            self::id((string) $sourceAccountId);
            self::id((string) $sourceEventId);
            if ($sourceAccountId === $this->accountId) {
                throw new InvalidArgumentException('Dynamic source cannot be the recipient');
            }
        }
        $input = compact('kind', 'day', 'base', 'rate', 'ruleId', 'sourceAccountId', 'sourceEventId');
        $fingerprint = self::fingerprint('release', $input);
        if (isset($this->events[$eventId])) {
            return [$this, $this->replay($eventId, $fingerprint)];
        }
        if ($this->complete()) {
            throw new DomainException('Cycle quota is exhausted');
        }
        if ($kind === self::STATIC && isset($this->staticDays[$day])) {
            throw new DomainException('Static release already exists for this day');
        }
        $sourceKey = $sourceEventId === null ? null : $kind . ':' . $sourceEventId;
        if ($sourceKey !== null && isset($this->sourceEvents[$sourceKey])) {
            throw new DomainException('Source event was already rewarded for this kind');
        }

        $expected = Decimal::mul($base, $rate);
        $paid = Decimal::min($expected, $this->remaining());
        $pocket = self::pocketFor($kind);
        $next = clone $this;
        $next->used = Decimal::add($this->used, $paid);
        $next->pockets[$pocket] = Decimal::add($this->pockets[$pocket], $paid);
        $event = [
            'id' => $eventId, 'type' => 'release', 'cycle_id' => $this->id,
            'kind' => $kind, 'day' => $day, 'source_account_id' => $sourceAccountId,
            'source_event_id' => $sourceEventId, 'base' => $base, 'rate' => $rate,
            'expected' => $expected, 'paid' => $paid, 'quota_before' => $this->used,
            'quota_after' => $next->used, 'pocket' => $pocket,
            'pocket_after' => $next->pockets[$pocket], 'rule_id' => $ruleId,
            'reason' => Decimal::cmp($paid, $expected) < 0 ? 'QUOTA_LIMITED' : null,
            'fingerprint' => $fingerprint,
        ];
        $next->events[$eventId] = $event;
        if ($kind === self::STATIC) {
            $next->staticDays[$day] = $eventId;
        }
        if ($sourceKey !== null) {
            $next->sourceEvents[$sourceKey] = $eventId;
        }
        return [$next, $event];
    }

    /** Move already released income; this never increases quota_used. */
    public function transferToWithdrawable(string $transferId, string $kind, mixed $amount): array
    {
        self::id($transferId);
        $pocket = self::pocketFor($kind);
        $amount = Decimal::amount($amount, true);
        $fingerprint = self::fingerprint('transfer', compact('kind', 'amount'));
        if (isset($this->events[$transferId])) {
            return [$this, $this->replay($transferId, $fingerprint)];
        }
        if (Decimal::cmp($this->pockets[$pocket], $amount) < 0) {
            throw new DomainException('Pending-transfer balance is insufficient');
        }
        $next = clone $this;
        $next->pockets[$pocket] = Decimal::sub($this->pockets[$pocket], $amount);
        $next->withdrawable = Decimal::add($this->withdrawable, $amount);
        $event = [
            'id' => $transferId, 'type' => 'transfer', 'cycle_id' => $this->id,
            'kind' => $kind, 'amount' => $amount, 'pocket' => $pocket,
            'pocket_after' => $next->pockets[$pocket], 'withdrawable_after' => $next->withdrawable,
            'quota_before' => $this->used, 'quota_after' => $next->used,
            'fingerprint' => $fingerprint,
        ];
        $next->events[$transferId] = $event;
        return [$next, $event];
    }

    /** A funded-program direct referral extends this round's total releasable amount. */
    public function grantReferralQuota(string $eventId, string $sourceAccountId,
        string $sourceEventId, mixed $amount, string $ruleId): array
    {
        self::id($eventId);
        self::id($sourceAccountId);
        self::id($sourceEventId);
        self::id($ruleId);
        if ($sourceAccountId === $this->accountId) {
            throw new DomainException('A cycle cannot grant its own referral bonus');
        }
        $amount = Decimal::amount($amount, true);
        $fingerprint = self::fingerprint('quota_bonus',
            compact('sourceAccountId', 'sourceEventId', 'amount', 'ruleId'));
        if (isset($this->events[$eventId])) {
            return [$this, $this->replay($eventId, $fingerprint)];
        }
        if ($this->complete() || isset($this->sourceEvents['quota_bonus:' . $sourceEventId])) {
            throw new DomainException('Referral bonus cannot be added to this cycle');
        }
        $next = clone $this;
        $next->bonusQuota = Decimal::add($this->bonusQuota, $amount);
        $event = [
            'id' => $eventId, 'type' => 'quota_bonus', 'cycle_id' => $this->id,
            'source_account_id' => $sourceAccountId, 'source_event_id' => $sourceEventId,
            'amount' => $amount, 'bonus_quota_after' => $next->bonusQuota,
            'effective_quota_after' => $next->effectiveQuota(),
            'rule_id' => $ruleId, 'fingerprint' => $fingerprint,
        ];
        $next->events[$eventId] = $event;
        $next->sourceEvents['quota_bonus:' . $sourceEventId] = $eventId;
        return [$next, $event];
    }

    public function used(): string { return $this->used; }
    public function effectiveQuota(): string { return Decimal::add($this->quota, $this->bonusQuota); }
    public function remaining(): string { return Decimal::sub($this->effectiveQuota(), $this->used); }
    public function complete(): bool { return Decimal::cmp($this->used, $this->effectiveQuota()) >= 0; }
    public function withdrawable(): string { return $this->withdrawable; }
    public function pocket(string $kind): string { return $this->pockets[self::pocketFor($kind)]; }
    public function event(string $eventId): ?array { return $this->events[$eventId] ?? null; }

    public function releasedOn(string $day): string
    {
        self::day($day);
        $total = '0';
        foreach ($this->events as $event) {
            if ($event['type'] === 'release' && $event['day'] === $day) {
                $total = Decimal::add($total, $event['paid']);
            }
        }
        return $total;
    }

    public function snapshot(): array
    {
        $snapshot = [
            'cycle_id' => $this->id, 'account_id' => $this->accountId,
            'principal' => $this->principal, 'price_usdt' => $this->priceUsdt,
            'value_usdt' => $this->valueUsdt, 'multiple' => $this->multiple,
            'quota' => $this->quota, 'used' => $this->used, 'remaining' => $this->remaining(),
            'complete' => $this->complete(), 'opening_rule_id' => $this->openingRuleId,
            'pending_transfer' => $this->pockets, 'withdrawable' => $this->withdrawable,
            'events' => array_values($this->events),
        ];
        if (Decimal::cmp($this->bonusQuota, '0') > 0) {
            $snapshot['bonus_quota'] = $this->bonusQuota;
            $snapshot['effective_quota'] = $this->effectiveQuota();
        }
        return $snapshot;
    }

    private function replay(string $eventId, string $fingerprint): array
    {
        $event = $this->events[$eventId];
        if ($event['fingerprint'] !== $fingerprint) {
            throw new DomainException('Event ID was reused with different input');
        }
        return $event;
    }

    private static function pocketFor(string $kind): string
    {
        return match ($kind) {
            self::STATIC => 'static',
            self::TEAM_ACCELERATION => 'team',
            self::DIRECT_REFERRAL => 'referral',
            default => throw new InvalidArgumentException('Unknown release kind'),
        };
    }

    private static function id(string $id): void
    {
        if ($id === '' || strlen($id) > 120 || !preg_match('/^[A-Za-z0-9:_-]+$/D', $id)) {
            throw new InvalidArgumentException('Invalid event or account ID');
        }
    }

    private static function day(string $day): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (!$date || $date->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException('Invalid business day');
        }
    }

    private static function fingerprint(string $type, array $input): string
    {
        return hash('sha256', json_encode([$type, $input], JSON_THROW_ON_ERROR));
    }
}
