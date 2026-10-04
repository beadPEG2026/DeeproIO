import {containDialogFocus} from '@/Functions/DialogFocus.mjs';

export default {
  watch: {
    showRiskModal(open) {
      if (this._disposeRiskDialog) { this._disposeRiskDialog(); this._disposeRiskDialog = null; }
      if (open) this.$nextTick(() => {
        if (this.showRiskModal && this.$refs.riskDialog) {
          this._disposeRiskDialog = containDialogFocus(this.$refs.riskDialog,() => { this.showRiskModal = false; });
        }
      });
    },
  },
  beforeDestroy() { if (this._disposeRiskDialog) this._disposeRiskDialog(); },
};
