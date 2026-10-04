<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Kyc/Index.template'
import AppLayout from '@/Layouts/AppLayout'
import TextUserInput from "@/Jetstream/TextUserInput";
import TButton from "@/Jetstream/Button";
import LoadingButton from "@/Jetstream/LoadingButton";

const defaultForm = {
    first_name: "",
    last_name: "",
    middle_name: "",
    country_id: null,
    document_type: 'id',
    document_number: "",
    phone: "",
    phone_code: "",
    front_id: null,
    selfie_id: null,
    back_id: null,
};

export default Template({
    components: {
        TButton,
        LoadingButton,
        TextUserInput,
        AppLayout,
    },

    props: {
        sumsub: {
            type: Boolean,
            default: false,
        },
        sumsub_pending: {
            type: Boolean,
            default: false,
        },
        sumsub_rejected: {
            type: Boolean,
            default: false,
        },
        isVerified: {
            type: Boolean,
            default: false,
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        errors: {
            type: Object,
            default: () => ({}),
        },
        countries: {
            type: Object,
            default: () => ({
                data: [],
            }),
        },
        pendingDocument: {
            type: Object,
            default: null,
        },
        rejectedDocument: {
            type: Object,
            default: null,
        },
    },

    data() {
        return {
            uploaded: false,
            uploadedSelfie: false,
            uploadedBack: false,

            documentPhoto: null,
            selfiePhoto: null,
            backPhoto: null,

            sending: false,
            sendingSelfie: false,
            sendingBack: false,

            sendingPhoneCode: false,
            phoneCountdown: 0,
            phoneCountdownTimer: null,

            selectedPhoneCountryCode: '',
            normalizingPhoneInput: false,

            allowedPhoneCountryCodes: [
                { key: 've', name: 'Venezuela', code: '+58' },
                { key: 'br', name: 'Brazil', code: '+55' },
                { key: 'my', name: 'Malaysia', code: '+60' },
                { key: 'us', name: 'United States', code: '+1' },
                { key: 'th', name: 'Thailand', code: '+66' },
                { key: 'tw', name: legacyText("台灣"), code: '+886' },
                { key: 'al', name: 'Albania', code: '+355' },
                { key: 'ad', name: 'Andorra', code: '+376' },
                { key: 'am', name: 'Armenia', code: '+374' },
                { key: 'at', name: 'Austria', code: '+43' },
                { key: 'az', name: 'Azerbaijan', code: '+994' },
                { key: 'by', name: 'Belarus', code: '+375' },
                { key: 'be', name: 'Belgium', code: '+32' },
                { key: 'ba', name: 'Bosnia and Herzegovina', code: '+387' },
                { key: 'bg', name: 'Bulgaria', code: '+359' },
                { key: 'hr', name: 'Croatia', code: '+385' },
                { key: 'cy', name: 'Cyprus', code: '+357' },
                { key: 'cz', name: 'Czech Republic', code: '+420' },
                { key: 'dk', name: 'Denmark', code: '+45' },
                { key: 'ee', name: 'Estonia', code: '+372' },
                { key: 'fo', name: 'Faroe Islands', code: '+298' },
                { key: 'fi', name: 'Finland', code: '+358' },
                { key: 'fr', name: 'France', code: '+33' },
                { key: 'ge', name: 'Georgia', code: '+995' },
                { key: 'de', name: 'Germany', code: '+49' },
                { key: 'gi', name: 'Gibraltar', code: '+350' },
                { key: 'gr', name: 'Greece', code: '+30' },
                { key: 'gl', name: 'Greenland', code: '+299' },
                { key: 'hu', name: 'Hungary', code: '+36' },
                { key: 'is', name: 'Iceland', code: '+354' },
                { key: 'ie', name: 'Ireland', code: '+353' },
                { key: 'it', name: 'Italy', code: '+39' },
                { key: 'xk', name: 'Kosovo', code: '+383' },
                { key: 'lv', name: 'Latvia', code: '+371' },
                { key: 'li', name: 'Liechtenstein', code: '+423' },
                { key: 'lt', name: 'Lithuania', code: '+370' },
                { key: 'lu', name: 'Luxembourg', code: '+352' },
                { key: 'mt', name: 'Malta', code: '+356' },
                { key: 'md', name: 'Moldova', code: '+373' },
                { key: 'mc', name: 'Monaco', code: '+377' },
                { key: 'me', name: 'Montenegro', code: '+382' },
                { key: 'nl', name: 'Netherlands', code: '+31' },
                { key: 'mk', name: 'North Macedonia', code: '+389' },
                { key: 'no', name: 'Norway', code: '+47' },
                { key: 'pl', name: 'Poland', code: '+48' },
                { key: 'pt', name: 'Portugal', code: '+351' },
                { key: 'ro', name: 'Romania', code: '+40' },
                { key: 'ru', name: 'Russia', code: '+7' },
                { key: 'sm', name: 'San Marino', code: '+378' },
                { key: 'rs', name: 'Serbia', code: '+381' },
                { key: 'sk', name: 'Slovakia', code: '+421' },
                { key: 'si', name: 'Slovenia', code: '+386' },
                { key: 'es', name: 'Spain', code: '+34' },
                { key: 'se', name: 'Sweden', code: '+46' },
                { key: 'ch', name: 'Switzerland', code: '+41' },
                { key: 'tr', name: 'Turkey', code: '+90' },
                { key: 'ua', name: 'Ukraine', code: '+380' },
                { key: 'gb', name: 'United Kingdom', code: '+44' },
                { key: 'va', name: 'Vatican City', code: '+39' }
            ],

            form: Object.assign({}, defaultForm),

            uploadUrl: this.route('user-file-upload'),
            uploadHeaders: {
                'X-XSRF-TOKEN': $cookies.get('XSRF-TOKEN')
            }
        }
    },

    computed: {
        isMultiple() {
            return this.form.document_type === "id" || this.form.document_type === "residence_permit";
        },

        actionButtonTitle() {
            return legacyText("Submit");
        },

        documentNumberLabel() {
            if (this.form.document_type === 'passport') {
                return legacyText("Passport Number");
            }

            if (this.form.document_type === 'driver_license') {
                return legacyText("Driver's License Number");
            }

            if (this.form.document_type === 'residence_permit') {
                return legacyText("Residence Permit Number");
            }

            return legacyText("ID Number");
        },
        canSendPhoneCode() {
            return !!this.selectedPhoneCountryCode && !!this.normalizePhoneNumber(this.form.phone);
        },

        currentPhoneCountry() {
            return this.allowedPhoneCountryCodes.find(item => item.code === this.selectedPhoneCountryCode) || null;
        },

        currentPhoneLocalMaxLength() {
            return this.getPhoneLocalMaxLength(this.selectedPhoneCountryCode);
        },
    },

    beforeDestroy() {
        this.destroyPhoneCountdownTimer();
    },

    beforeUnmount() {
        this.destroyPhoneCountdownTimer();
    },

    methods: {
        submit() {
            if (this.sending) {
                return;
            }

            const phone = this.getPreparedPhone();

            if (!phone) {
                return;
            }

            this.form.document_number = this.normalizeDocumentNumber(this.form.document_number);

            const submitForm = {
                ...this.form,
                phone: phone,
            };

            this.sending = true;

            let afterRequest = {
                onStart: () => {
                    this.sending = true;
                },
                onFinish: () => {
                    this.sending = false;
                },
                onSuccess: () => {
                    this.sending = false;

                    if (this.$toast) {
                        this.$toast.open(this.$t('KYC Documents were submitted'));
                    }
                },
                onError: () => {
                    this.sending = false;

                    if (this.$toast) {
                        this.$toast.error(this.$t('There are some form errors'));
                    }
                },
                preserveScroll: true,
            };

            this.$inertia.post(this.route('user.kyc.store'), submitForm, afterRequest);
        },

        sendPhoneVerificationCode() {
            const phone = this.getPreparedPhone();

            if (!phone) {
                return;
            }

            this.sendingPhoneCode = true;

            axios.post('/api/v1/register/email/code/send', {
                type: 'phone',
                phone: phone
            }).then(() => {
                this.$toast.success(this.$t('Verification code sent successfully'));
                this.startPhoneCountdown();
            }).catch((error) => {
                this.$toast.error(
                    error.response?.data?.message || this.$t('Failed to send verification code')
                );
            }).finally(() => {
                this.sendingPhoneCode = false;
            });
        },
        startPhoneCountdown() {
            this.phoneCountdown = 60;

            if (this.phoneCountdownTimer) {
                clearInterval(this.phoneCountdownTimer);
            }

            this.phoneCountdownTimer = setInterval(() => {
                if (this.phoneCountdown > 0) {
                    this.phoneCountdown--;
                    return;
                }

                this.destroyPhoneCountdownTimer();
            }, 1000);
        },

        destroyPhoneCountdownTimer() {
            if (this.phoneCountdownTimer) {
                clearInterval(this.phoneCountdownTimer);
                this.phoneCountdownTimer = null;
            }
        },

        getPhoneLocalMaxLength(countryCode) {
            const code = String(countryCode || '');

            const maxLengthMap = {
                '+420': 9,
            };

            return maxLengthMap[code] || 15;
        },

        onPhoneCountryCodeChange() {
            /*
             * 选择完区号以后，不再自动识别其他区号。
             * 这里只处理用户粘贴了同一个已选区号的情况，避免重复拼接。
             */
            this.removeSelectedCountryCodeFromInput();
            this.onPhoneInput();
        },

        onPhoneInput() {
            if (this.normalizingPhoneInput) {
                return;
            }

            const raw = this.normalizeAccount(this.form.phone);

            /*
             * 没有选择区号时，才自动识别。
             * 例如输入 +420123456789，会自动选择 +420，并把输入框改成 123456789。
             */
            if (!this.selectedPhoneCountryCode) {
                this.detectAndRemoveCountryCode(raw);
                return;
            }

            let number = this.normalizePhoneNumber(raw);
            const codeDigits = String(this.selectedPhoneCountryCode || '').replace(/[^\d]/g, '');

            /*
             * 已选择区号时，不自动切换国家。
             * 只在用户明确粘贴 +已选区号 或 00已选区号 时去掉重复区号。
             */
            if (codeDigits && raw.indexOf('+') === 0 && number.indexOf(codeDigits) === 0) {
                number = number.substring(codeDigits.length);
            } else if (codeDigits && number.indexOf('00' + codeDigits) === 0) {
                number = number.substring(('00' + codeDigits).length);
            }

            const maxLength = this.currentPhoneLocalMaxLength || 15;

            if (number.length > maxLength) {
                number = number.substring(0, maxLength);
            }

            if (this.form.phone !== number) {
                this.normalizingPhoneInput = true;
                this.form.phone = number;

                this.$nextTick(() => {
                    this.normalizingPhoneInput = false;
                });
            }
        },

        onPhoneCodeInput() {
            this.form.phone_code = String(this.form.phone_code || '').replace(/[^\d]/g, '').slice(0, 6);
        },

        getPreparedPhone() {
            if (!this.selectedPhoneCountryCode) {
                if (this.$toast) {
                    this.$toast.error(this.$t('Please select country code'));
                }

                return '';
            }

            const phoneNumber = this.normalizePhoneNumber(this.form.phone);

            if (!phoneNumber) {
                if (this.$toast) {
                    this.$toast.error(this.$t('Please enter a valid mobile phone number'));
                }

                return '';
            }

            return this.buildFullPhoneNumber(this.selectedPhoneCountryCode, phoneNumber);
        },

        detectAndRemoveCountryCode(value) {
            if (this.normalizingPhoneInput) {
                return;
            }

            const account = this.normalizeAccount(value);

            if (!account) {
                return;
            }

            const detected = this.findCountryCodeFromInput(account);

            if (!detected) {
                return;
            }

            this.selectedPhoneCountryCode = detected.code;

            this.normalizingPhoneInput = true;
            this.form.phone = detected.localNumber;

            this.$nextTick(() => {
                this.normalizingPhoneInput = false;
            });
        },

        findCountryCodeFromInput(value) {
            const raw = this.normalizeAccount(value);

            if (!raw) {
                return null;
            }

            const codes = this.allowedPhoneCountryCodes
                .slice()
                .sort((a, b) => {
                    return b.code.replace(/[^\d]/g, '').length - a.code.replace(/[^\d]/g, '').length;
                });

            const compactRaw = raw.replace(/[\s\-().]/g, '');

            if (compactRaw.indexOf('+') === 0) {
                const digits = compactRaw.replace(/[^\d]/g, '');

                return this.matchCountryCodeFromDigits(digits, codes);
            }

            if (compactRaw.indexOf('00') === 0) {
                const digits = compactRaw.replace(/[^\d]/g, '').replace(/^00/, '');

                return this.matchCountryCodeFromDigits(digits, codes);
            }

            const digitsOnly = compactRaw.replace(/[^\d]/g, '');

            if (!digitsOnly) {
                return null;
            }

            return this.matchCountryCodeFromDigits(digitsOnly, codes, true);
        },

        matchCountryCodeFromDigits(digits, codes, bareNumber = false) {
            for (const item of codes) {
                const codeDigits = item.code.replace(/[^\d]/g, '');

                if (!codeDigits) {
                    continue;
                }

                if (bareNumber && digits.length <= codeDigits.length + 5) {
                    continue;
                }

                if (digits.indexOf(codeDigits) === 0 && digits.length > codeDigits.length) {
                    return {
                        code: item.code,
                        localNumber: digits.substring(codeDigits.length)
                    };
                }
            }

            return null;
        },

        removeSelectedCountryCodeFromInput() {
            if (this.normalizingPhoneInput) {
                return;
            }

            if (!this.selectedPhoneCountryCode) {
                return;
            }

            const account = this.normalizeAccount(this.form.phone);

            if (!account) {
                return;
            }

            const raw = account.replace(/[\s\-().]/g, '');
            const digits = this.normalizePhoneNumber(account);
            const codeDigits = this.selectedPhoneCountryCode.replace(/[^\d]/g, '');

            if (!digits || !codeDigits) {
                return;
            }

            let localNumber = '';

            if (raw.indexOf('+') === 0 && digits.indexOf(codeDigits) === 0) {
                localNumber = digits.substring(codeDigits.length);
            } else if (digits.indexOf('00' + codeDigits) === 0) {
                localNumber = digits.substring(('00' + codeDigits).length);
            }

            if (!localNumber) {
                return;
            }

            this.normalizingPhoneInput = true;
            this.form.phone = localNumber;

            this.$nextTick(() => {
                this.normalizingPhoneInput = false;
            });
        },

        normalizeAccount(value) {
            return (value || '').toString().trim();
        },

        normalizePhoneNumber(value) {
            return this.normalizeAccount(value).replace(/[^\d]/g, '');
        },

        buildFullPhoneNumber(countryCode, phoneNumber) {
            const code = (countryCode || '').replace(/[^\d]/g, '');
            const raw = this.normalizeAccount(phoneNumber).replace(/[\s\-().]/g, '');
            let number = raw.replace(/[^\d]/g, '');

            if (code && raw.indexOf('+') === 0 && number.indexOf(code) === 0) {
                number = number.substring(code.length);
            } else if (code && number.indexOf('00' + code) === 0) {
                number = number.substring(('00' + code).length);
            }

            return '+' + code + number;
        },

        normalizeDocumentNumber(value) {
            if (value === null || value === undefined) {
                return '';
            }

            return String(value)
                .trim()
                .replace(/[\s-]+/g, '')
                .toUpperCase();
        },

        onDocumentNumberInput() {
            this.form.document_number = this.normalizeDocumentNumber(this.form.document_number);
        },

        removeBackPic() {
            this.backPhoto = null;
            this.form.back_id = null;
            this.uploadedBack = false;
        },

        removeSelfiePic() {
            this.selfiePhoto = null;
            this.form.selfie_id = null;
            this.uploadedSelfie = false;
        },

        removePic() {
            this.documentPhoto = null;
            this.form.front_id = null;
            this.uploaded = false;
        },

        upload() {
            if (!this.documentPhoto || !this.$refs.fileUploadForm) {
                return;
            }

            let self = this;

            this.$refs.fileUploadForm.upload(this.uploadUrl, this.uploadHeaders, [this.documentPhoto]).then(function () {
                self.uploaded = true;
            });
        },

        uploadSelfie() {
            if (!this.selfiePhoto || !this.$refs.selfieUploadForm) {
                return;
            }

            let self = this;

            this.$refs.selfieUploadForm.upload(this.uploadUrl, this.uploadHeaders, [this.selfiePhoto]).then(function () {
                self.uploadedSelfie = true;
            });
        },

        uploadBack() {
            if (!this.backPhoto || !this.$refs.backUploadForm) {
                return;
            }

            let self = this;

            this.$refs.backUploadForm.upload(this.uploadUrl, this.uploadHeaders, [this.backPhoto]).then(function () {
                self.uploadedBack = true;
            });
        },

        onSelect(fileRecords) {
            this.uploaded = false;
            this.upload();
        },

        onSelectSelfie(fileRecords) {
            this.uploadedSelfie = false;
            this.uploadSelfie();
        },

        onSelectBack(fileRecords) {
            this.uploadedBack = false;
            this.uploadBack();
        },

        onUpload(responses) {
            let response = responses && responses.length ? responses[0] : null;

            if (response && !response.error && response.data && response.data.uuid) {
                this.form.front_id = response.data.uuid;
            }
        },

        onUploadSelfie(responses) {
            let response = responses && responses.length ? responses[0] : null;

            if (response && !response.error && response.data && response.data.uuid) {
                this.form.selfie_id = response.data.uuid;
            }
        },

        onUploadBack(responses) {
            let response = responses && responses.length ? responses[0] : null;

            if (response && !response.error && response.data && response.data.uuid) {
                this.form.back_id = response.data.uuid;
            }
        },

        startKyc() {
            if (!this.$page.props.user || !this.$page.props.user.referral_code) {
                return;
            }

            window.location.href = this.route('sumsub.main', this.$page.props.user.referral_code);
        }
    },

    watch: {
        'form.document_type': function () {
            this.documentPhoto = null;
            this.backPhoto = null;

            this.form.front_id = null;
            this.form.back_id = null;

            this.uploaded = false;
            this.uploadedBack = false;
        },
    }
})
</script>
    <style>
        .kyc-phone-verify-card {
            width: 100%;
            padding: 18px;
            border-radius: 18px;
            border: 1px solid rgba(99, 102, 241, 0.28);
            background:
                radial-gradient(circle at top left, rgba(99, 102, 241, 0.16), transparent 34%),
                rgba(15, 23, 42, 0.035);
            box-shadow: 0 14px 38px rgba(15, 23, 42, 0.06);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        .kyc-phone-verify-card:hover {
            border-color: rgba(99, 102, 241, 0.45);
            box-shadow: 0 18px 46px rgba(15, 23, 42, 0.09);
        }

        .kyc-phone-verify-card.invalid {
            border-color: rgba(239, 68, 68, 0.46);
        }

        .kyc-phone-verify-card.is-sent {
            border-color: rgba(34, 197, 94, 0.42);
        }

        .kyc-phone-verify-card__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 16px;
        }

        .kyc-phone-verify-card__title {
            font-size: 15px;
            font-weight: 700;
            line-height: 1.35;
        }

        .kyc-phone-verify-card__desc {
            margin-top: 4px;
            font-size: 12px;
            line-height: 1.65;
            opacity: 0.68;
        }

        .kyc-phone-verify-card__body {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(260px, 0.95fr);
            gap: 14px;
            align-items: start;
        }

        .kyc-phone-verify-card__field label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 600;
            opacity: 0.78;
        }

        .kyc-phone-verify-card__field input {
            width: 100%;
            height: 46px;
            padding: 0 14px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(255, 255, 255, 0.045);
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .kyc-phone-verify-card__field input:focus {
            border-color: rgba(99, 102, 241, 0.72);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.12);
            background: rgba(255, 255, 255, 0.075);
        }

        .kyc-phone-verify-card__code-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .kyc-phone-verify-card__code-row input {
            flex: 1;
            min-width: 0;
        }

        .kyc-phone-verify-card__send {
            min-width: 98px;
            height: 46px;
            padding: 0 16px;
            border-radius: 12px;
            border: 0;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            cursor: pointer;
            transition: opacity 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
            box-shadow: 0 10px 22px rgba(79, 70, 229, 0.24);
        }

        .kyc-phone-verify-card__send:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 26px rgba(79, 70, 229, 0.28);
        }

        .kyc-phone-verify-card__send:disabled {
            opacity: 0.52;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .kyc-phone-verify-card .helper-text {
            display: block;
            margin-top: 6px;
        }

        @media (max-width: 768px) {
            .kyc-phone-verify-card {
                padding: 15px;
            }

            .kyc-phone-verify-card__body {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .kyc-phone-verify-card__send {
                min-width: 86px;
                padding: 0 12px;
            }
        }
        .kyc-phone-verify-card {
            width: 100%;
            padding: 18px;
            border-radius: 18px;
            border: 1px solid rgba(99, 102, 241, 0.28);
            background:
                radial-gradient(circle at top left, rgba(99, 102, 241, 0.12), transparent 34%),
                rgba(15, 23, 42, 0.035);
            box-shadow: 0 14px 38px rgba(15, 23, 42, 0.06);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .kyc-phone-verify-card:hover {
            border-color: rgba(99, 102, 241, 0.45);
            box-shadow: 0 18px 46px rgba(15, 23, 42, 0.09);
        }

        .kyc-phone-verify-card.invalid {
            border-color: rgba(239, 68, 68, 0.46);
        }

        .kyc-phone-verify-card.is-sent {
            border-color: rgba(34, 197, 94, 0.42);
        }

        .kyc-phone-verify-card__body {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(260px, 0.95fr);
            gap: 14px;
            align-items: start;
        }

        .kyc-phone-verify-card__field label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 600;
            opacity: 0.78;
        }

        .kyc-phone-input-row,
        .kyc-phone-verify-card__code-row {
            display: flex;
            align-items: stretch;
            gap: 10px;
        }

        .kyc-phone-country-select {
            width: 92px;
            min-width: 92px;
            height: 46px;
            padding: 0 32px 0 12px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(255, 255, 255, 0.045);
            color: inherit;
            outline: none;
            cursor: pointer;
            font-size: 12px;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }

        .kyc-phone-country-select option {
            color: #111827;
            background: #ffffff;
        }

        .kyc-phone-verify-card__field input {
            width: 100%;
            height: 46px;
            padding: 0 14px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(255, 255, 255, 0.045);
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .kyc-phone-input-row input,
        .kyc-phone-verify-card__code-row input {
            flex: 1;
            min-width: 0;
        }

        .kyc-phone-country-select:focus,
        .kyc-phone-verify-card__field input:focus {
            border-color: rgba(99, 102, 241, 0.72);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.12);
            background: rgba(255, 255, 255, 0.075);
        }

        .kyc-phone-verify-card__send {
            min-width: 98px;
            height: 46px;
            padding: 0 16px;
            border-radius: 12px;
            border: 0;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            cursor: pointer;
            transition: opacity 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
            box-shadow: 0 10px 22px rgba(79, 70, 229, 0.24);
        }

        .kyc-phone-verify-card__send:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 26px rgba(79, 70, 229, 0.28);
        }

        .kyc-phone-verify-card__send:disabled {
            opacity: 0.52;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .kyc-phone-verify-card .helper-text {
            display: block;
            margin-top: 6px;
        }

        @media (max-width: 768px) {
            .kyc-phone-verify-card {
                padding: 15px;
            }

            .kyc-phone-verify-card__body {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .kyc-phone-country-select {
                width: 88px;
                min-width: 88px;
                padding-left: 10px;
                font-size: 11px;
            }

            .kyc-phone-verify-card__send {
                min-width: 86px;
                padding: 0 12px;
            }
        }
    </style>