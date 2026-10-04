<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/ColdStorage/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetDangerButton from '@/Jetstream/DangerButton'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        JetDialogModal,
        JetDangerButton,
        JetSecondaryButton,
    },

    props: {
        coldStorage: Object,

        needGoogleVerify: {
            type: Boolean,
            default: false,
        },
    },

    data() {
        return {
            sending: false,

            showGoogleVerifyModal: false,
            googleCode: '',
            verifyingGoogleCode: false,
            googleVerified: !this.needGoogleVerify,

            showDepositBankCardModal: false,
            selectedStorage: null,
            depositBankCardForm: {
                address: '',
                status: true,
            },
        }
    },

    mounted() {
        if (this.needGoogleVerify) {
            this.showGoogleVerifyModal = true
        }
    },

    methods: {
        coldStorageCreateUrl() {
            return '/exchange-control-panel/cold-storage/create'
        },

        coldStorageEditUrl(id) {
            return `/exchange-control-panel/cold-storage/${id}/edit`
        },

        verifyGoogleCode() {
            if (this.verifyingGoogleCode) return

            if (!this.googleCode || this.googleCode.length !== 6) {
                this.$toast.error(legacyText("请输入 6 位验证码"))
                return
            }

            this.verifyingGoogleCode = true

            axios.post('/exchange-control-panel/cold-storage/google-verify', {
                code: this.googleCode
            }).then((res) => {
                this.verifyingGoogleCode = false

                if (res.data.success) {
                    this.googleVerified = true
                    this.$toast.success(legacyText("验证成功"))
                    this.showGoogleVerifyModal = false
                    this.googleCode = ''
                } else {
                    this.$toast.error(res.data.message || legacyText("验证失败"))
                }
            }).catch((error) => {
                this.verifyingGoogleCode = false

                if (error.response && error.response.data && error.response.data.message) {
                    this.$toast.error(error.response.data.message)
                } else if (
                    error.response &&
                    error.response.data &&
                    error.response.data.errors &&
                    error.response.data.errors.code
                ) {
                    this.$toast.error(error.response.data.errors.code[0])
                } else {
                    this.$toast.error(legacyText("验证失败"))
                }
            })
        },

        depositBankCardUpdateUrl(id) {
            return `/exchange-control-panel/cold-storage/${id}/deposit-bank-card`
        },

        getDepositBankCardStorage() {
            if (
                !this.coldStorage ||
                !this.coldStorage.data ||
                !Array.isArray(this.coldStorage.data)
            ) {
                return null
            }

            return this.coldStorage.data.find((storage) => {
                return parseInt(storage.id) === 1
            }) || null
        },

        openDepositBankCardModal() {
            if (!this.googleVerified) {
                this.showGoogleVerifyModal = true
                return
            }

            const storage = this.getDepositBankCardStorage()

            if (!storage) {
                this.$toast.error(legacyText("未找到 ID 为 1 的入金银行卡数据"))
                return
            }

            this.selectedStorage = storage

            this.depositBankCardForm = {
                address: storage.address || '',
                status: !!storage.status,
            }

            this.showDepositBankCardModal = true
        },

        closeDepositBankCardModal() {
            if (this.sending) {
                return
            }

            this.showDepositBankCardModal = false
            this.selectedStorage = null
            this.depositBankCardForm = {
                address: '',
                status: true,
            }
        },

        submitDepositBankCard() {
            if (this.sending) {
                return
            }

            if (!this.selectedStorage) {
                this.$toast.error(legacyText("未找到需要修改的数据"))
                return
            }

            if (parseInt(this.selectedStorage.id) !== 1) {
                this.$toast.error(legacyText("入金银行卡只能修改 ID 为 1 的数据"))
                return
            }

            const address = String(this.depositBankCardForm.address || '').trim()

            if (!address) {
                this.$toast.error(legacyText("请输入入金银行卡信息"))
                return
            }

            this.sending = true

            axios.put(
                this.depositBankCardUpdateUrl(this.selectedStorage.id),
                {
                    address: address,
                    status: this.depositBankCardForm.status ? 1 : 0,
                }
            ).then((response) => {
                this.$toast.success(
                    response.data && response.data.message
                        ? response.data.message
                        : legacyText("修改成功")
                )

                this.selectedStorage.address = address
                this.selectedStorage.status = this.depositBankCardForm.status

                this.closeDepositBankCardModal()

                if (this.$inertia && this.$inertia.reload) {
                    this.$inertia.reload({
                        preserveScroll: true,
                    })
                }
            }).catch((error) => {
                if (
                    error.response &&
                    error.response.status === 403 &&
                    error.response.data &&
                    error.response.data.need_google_verify
                ) {
                    this.googleVerified = false
                    this.showGoogleVerifyModal = true
                    return
                }

                if (error.response && error.response.data && error.response.data.message) {
                    this.$toast.error(error.response.data.message)
                } else if (
                    error.response &&
                    error.response.data &&
                    error.response.data.errors &&
                    error.response.data.errors.address
                ) {
                    this.$toast.error(error.response.data.errors.address[0])
                } else {
                    this.$toast.error(legacyText("修改失败"))
                }
            }).finally(() => {
                this.sending = false
            })
        },
    },
})
</script>