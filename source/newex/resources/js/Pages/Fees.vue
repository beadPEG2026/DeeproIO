<script>
import Template from '{Template}/Web/Pages/Fees.template'
import AppLayout from '@/Layouts/AppLayout'

export default Template({
  components: { AppLayout },
  props: {
    currencies: Array, makerFee:Number,takerFee:Number,futuresMakerFee:Number,futuresTakerFee:Number,
  },
  methods: {
    fmt(v, d = 8) {
      if (v === null || v === undefined || v === '') return '0'
      const num = Number(v)
      if (isNaN(num)) return v
      return num.toFixed(d)
    },
    networkKeyBySlug(slug) {
      // map network slug to currency fee suffix keys
      const map = {
        erc20: 'erc',
        bep20: 'bep',
        trc20: 'trc',
        matic20: 'matic',
        solspl: 'sol',
        matic: 'matic',
        sol: 'sol',
      }
      return map[slug] || null
    },
    withdrawalNetworkKeyBySlug(slug) {
      // Keep this list in sync with WithdrawalFeeService. Native MATIC/SOL
      // use the generic fee fields; only token networks use suffix fields.
      const map = {
        erc20: 'erc',
        bep20: 'bep',
        trc20: 'trc',
        matic20: 'matic',
        solspl: 'sol',
      }
      return map[slug] || null
    },
    // Build per-network Deposit Fee display string using percent + fixed parts
    depositFeeFor(c, netSlug) {
      const key = this.networkKeyBySlug(netSlug)
      const percent = (c.deposit_fee !== undefined && c.deposit_fee !== null && c.deposit_fee !== '') ? `${Number(c.deposit_fee).toFixed(4)}%` : ''
      let fixed = ''
      if (key && c[`deposit_fee_${key}_fixed`] !== undefined && c[`deposit_fee_${key}_fixed`] !== null) {
        fixed = this.fmt(c[`deposit_fee_${key}_fixed`])
      } else if (c.deposit_fee_fixed !== undefined && c.deposit_fee_fixed !== null) {
        fixed = this.fmt(c.deposit_fee_fixed)
      }
      if (percent && fixed && Number(fixed) !== 0) return `${fixed}`
      if (percent) return percent
      return fixed || '0'
    },
    // Build per-network Withdrawal Fee using the same priority as the
    // withdrawal backend: a percentage fee wins when it is configured;
    // otherwise the fixed fee is used.
    withdrawFeeFor(c, netSlug) {
      const key = this.withdrawalNetworkKeyBySlug(netSlug)
      const variableValue = key && c[`withdraw_fee_${key}`] !== undefined && c[`withdraw_fee_${key}`] !== null
        ? c[`withdraw_fee_${key}`]
        : c.withdraw_fee
      const fixedValue = key && c[`withdraw_fee_${key}_fixed`] !== undefined && c[`withdraw_fee_${key}_fixed`] !== null
        ? c[`withdraw_fee_${key}_fixed`]
        : c.withdraw_fee_fixed

      if (variableValue !== undefined && variableValue !== null && variableValue !== '' && Number(variableValue) > 0) {
        return `${this.fmt(variableValue)}%`
      }

      return this.fmt(fixedValue)
    },
    networkName(n) {
      // Prefer readable name if exists, else slug uppercased
      if (!n) return ''
      if (n.name) return n.name
      if (n.slug) return n.slug.toUpperCase()
      return `#${n.id}`
    }
  }
})
</script>
