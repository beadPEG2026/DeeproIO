<template>
<div class="phone-input-wrapper">
    <div class="phone-input" :class="{'form-error': hasError}">
        <div class="phone-input__country-selector" @click="toggleCountryList">
            <span class="phone-input__flag" v-html="selectedCountry.flag"></span>
            <span class="phone-input__code">+{{ selectedCountry.code }}</span>
            <svg class="phone-input__arrow" width="12" height="8" viewBox="0 0 12 8" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M1 1L6 6L11 1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <input
            :type="type"
            :value="phoneNumber"
            @input="onInput"
            :placeholder="placeholder"
            :class="inputClass"
            :disabled="disabled"
            autocomplete="tel"
        />
    </div>
    <div v-if="showCountryList" class="phone-input__country-list" ref="countryList">
        <div class="phone-input__search">
            <input
                type="text"
                v-model="searchQuery"
                :placeholder="$t('Search country')"
                class="phone-input__search-input"
            />
        </div>
        <div class="phone-input__country-items">
            <div
                v-for="country in filteredCountries"
                :key="country.iso"
                class="phone-input__country-item"
                @click="selectCountry(country)"
            >
                <span class="phone-input__flag" v-html="country.flag"></span>
                <span class="phone-input__country-name">{{ country.name }}</span>
                <span class="phone-input__country-code">+{{ country.code }}</span>
            </div>
        </div>
    </div>
    <span v-if="hasError && errorMessage" class="form-error__text">{{ errorMessage }}</span>
</div>
</template>

<script>
export default {
    name: 'PhoneInput',
    props: {
        value: {
            type: String,
            default: ''
        },
        countryCode: {
            type: String,
            default: 'US'
        },
        placeholder: {
            type: String,
            default: ''
        },
        inputClass: {
            type: String,
            default: 'form-components__block-input'
        },
        disabled: {
            type: Boolean,
            default: false
        },
        type: {
            type: String,
            default: 'tel'
        },
        error: {
            type: String,
            default: null
        }
    },
    data() {
        return {
            showCountryList: false,
            searchQuery: '',
            selectedCountry: null,
            phoneNumber: '',
            countries: [
                { iso: 'US', code: '1', name: 'United States', flag: '🇺🇸' },
                { iso: 'GB', code: '44', name: 'United Kingdom', flag: '🇬🇧' },
                { iso: 'CA', code: '1', name: 'Canada', flag: '🇨🇦' },
                { iso: 'AU', code: '61', name: 'Australia', flag: '🇦🇺' },
                { iso: 'DE', code: '49', name: 'Germany', flag: '🇩🇪' },
                { iso: 'FR', code: '33', name: 'France', flag: '🇫🇷' },
                { iso: 'IT', code: '39', name: 'Italy', flag: '🇮🇹' },
                { iso: 'ES', code: '34', name: 'Spain', flag: '🇪🇸' },
                { iso: 'NL', code: '31', name: 'Netherlands', flag: '🇳🇱' },
                { iso: 'BE', code: '32', name: 'Belgium', flag: '🇧🇪' },
                { iso: 'CH', code: '41', name: 'Switzerland', flag: '🇨🇭' },
                { iso: 'AT', code: '43', name: 'Austria', flag: '🇦🇹' },
                { iso: 'SE', code: '46', name: 'Sweden', flag: '🇸🇪' },
                { iso: 'NO', code: '47', name: 'Norway', flag: '🇳🇴' },
                { iso: 'DK', code: '45', name: 'Denmark', flag: '🇩🇰' },
                { iso: 'FI', code: '358', name: 'Finland', flag: '🇫🇮' },
                { iso: 'PL', code: '48', name: 'Poland', flag: '🇵🇱' },
                { iso: 'CZ', code: '420', name: 'Czech Republic', flag: '🇨🇿' },
                { iso: 'GR', code: '30', name: 'Greece', flag: '🇬🇷' },
                { iso: 'PT', code: '351', name: 'Portugal', flag: '🇵🇹' },
                { iso: 'IE', code: '353', name: 'Ireland', flag: '🇮🇪' },
                { iso: 'RU', code: '7', name: 'Russia', flag: '🇷🇺' },
                { iso: 'JP', code: '81', name: 'Japan', flag: '🇯🇵' },
                { iso: 'CN', code: '86', name: 'China', flag: '🇨🇳' },
                { iso: 'IN', code: '91', name: 'India', flag: '🇮🇳' },
                { iso: 'KR', code: '82', name: 'South Korea', flag: '🇰🇷' },
                { iso: 'SG', code: '65', name: 'Singapore', flag: '🇸🇬' },
                { iso: 'MY', code: '60', name: 'Malaysia', flag: '🇲🇾' },
                { iso: 'TH', code: '66', name: 'Thailand', flag: '🇹🇭' },
                { iso: 'ID', code: '62', name: 'Indonesia', flag: '🇮🇩' },
                { iso: 'PH', code: '63', name: 'Philippines', flag: '🇵🇭' },
                { iso: 'VN', code: '84', name: 'Vietnam', flag: '🇻🇳' },
                { iso: 'BR', code: '55', name: 'Brazil', flag: '🇧🇷' },
                { iso: 'MX', code: '52', name: 'Mexico', flag: '🇲🇽' },
                { iso: 'AR', code: '54', name: 'Argentina', flag: '🇦🇷' },
                { iso: 'CL', code: '56', name: 'Chile', flag: '🇨🇱' },
                { iso: 'CO', code: '57', name: 'Colombia', flag: '🇨🇴' },
                { iso: 'PE', code: '51', name: 'Peru', flag: '🇵🇪' },
                { iso: 'ZA', code: '27', name: 'South Africa', flag: '🇿🇦' },
                { iso: 'EG', code: '20', name: 'Egypt', flag: '🇪🇬' },
                { iso: 'NG', code: '234', name: 'Nigeria', flag: '🇳🇬' },
                { iso: 'KE', code: '254', name: 'Kenya', flag: '🇰🇪' },
                { iso: 'AE', code: '971', name: 'United Arab Emirates', flag: '🇦🇪' },
                { iso: 'SA', code: '966', name: 'Saudi Arabia', flag: '🇸🇦' },
                { iso: 'IL', code: '972', name: 'Israel', flag: '🇮🇱' },
                { iso: 'TR', code: '90', name: 'Turkey', flag: '🇹🇷' },
                { iso: 'NZ', code: '64', name: 'New Zealand', flag: '🇳🇿' },
            ]
        }
    },
    computed: {
        hasError() {
            return !!this.error;
        },
        errorMessage() {
            return this.error;
        },
        filteredCountries() {
            if (!this.searchQuery) {
                return this.countries;
            }
            const query = this.searchQuery.toLowerCase();
            return this.countries.filter(country => 
                country.name.toLowerCase().includes(query) ||
                country.code.includes(query) ||
                country.iso.toLowerCase().includes(query)
            );
        },
        fullPhoneNumber() {
            if (!this.phoneNumber) {
                return '';
            }
            return `+${this.selectedCountry.code}${this.phoneNumber}`;
        }
    },
    mounted() {
        this.initializeCountry();
        this.parseInitialValue();
        document.addEventListener('click', this.handleClickOutside);
    },
    beforeDestroy() {
        document.removeEventListener('click', this.handleClickOutside);
    },
    watch: {
        value(newVal) {
            if (newVal !== this.fullPhoneNumber) {
                this.parseInitialValue();
            }
        },
        countryCode(newCode) {
            const country = this.countries.find(c => c.iso === newCode);
            if (country) {
                this.selectedCountry = country;
            }
        }
    },
    methods: {
        initializeCountry() {
            const country = this.countries.find(c => c.iso === this.countryCode) || this.countries[0];
            this.selectedCountry = country;
        },
        parseInitialValue() {
            if (!this.value) {
                this.phoneNumber = '';
                return;
            }
            
            // Parse full phone number format: +1234567890
            const match = this.value.match(/^\+(\d+)(.*)$/);
            if (match) {
                const code = match[1];
                const number = match[2];
                const country = this.countries.find(c => c.code === code);
                if (country) {
                    this.selectedCountry = country;
                    this.phoneNumber = number;
                } else {
                    this.phoneNumber = this.value.replace(/^\+/, '');
                }
            } else {
                this.phoneNumber = this.value;
            }
        },
        toggleCountryList() {
            if (this.disabled) return;
            this.showCountryList = !this.showCountryList;
            if (this.showCountryList) {
                this.$nextTick(() => {
                    this.searchQuery = '';
                });
            }
        },
        selectCountry(country) {
            this.selectedCountry = country;
            this.showCountryList = false;
            this.searchQuery = '';
            this.emitValue();
        },
        onInput(event) {
            // Only allow digits
            const value = event.target.value.replace(/\D/g, '');
            this.phoneNumber = value;
            this.emitValue();
        },
        emitValue() {
            this.$emit('input', this.fullPhoneNumber);
            this.$emit('change', {
                phone: this.fullPhoneNumber,
                countryCode: this.selectedCountry.code,
                countryIso: this.selectedCountry.iso,
                phoneNumber: this.phoneNumber
            });
        },
        handleClickOutside(event) {
            if (this.$refs.countryList && !this.$refs.countryList.contains(event.target) &&
                !event.target.closest('.phone-input__country-selector')) {
                this.showCountryList = false;
            }
        }
    }
}
</script>

<style scoped>
/* Base styles - will be overridden by SCSS file */
.phone-input-wrapper {
    position: relative;
    width: 100%;
    flex: 1;
}

.phone-input__country-selector {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 0 8px 0 0;
    cursor: pointer;
    border-right: 1px solid var(--border-line-color, #e5e7eb);
    margin-right: 8px;
    min-width: 90px;
    height: 100%;
    user-select: none;
    flex-shrink: 0;
}

.phone-input__flag {
    font-size: 20px;
    line-height: 1;
}

.phone-input__code {
    font-weight: 500;
    color: var(--first-text-color, #1f2937);
    font-size: 14px;
}

.phone-input__arrow {
    margin-left: auto;
    color: var(--second-text-color, #6b7280);
    transition: transform 0.2s;
}

.phone-input__country-selector:hover .phone-input__arrow {
    transform: rotate(180deg);
}

.phone-input input {
    flex: 1;
    border: none;
    outline: none;
    padding: 0.80rem 0.80rem 0.80rem 0;
    background: transparent;
    font-weight: 500;
    font-size: 0.90rem;
    color: var(--first-text-color, #1f2937);
    width: 100%;
}

.phone-input input::placeholder {
    color: var(--second-text-color, #9ca3af);
}

.phone-input input:hover {
    outline: none;
    border: none;
    background-color: transparent !important;
}

.phone-input input:focus {
    outline: none;
    border: none;
    box-shadow: none;
    background-color: transparent !important;
    color: var(--first-text-color, #1f2937);
}

.phone-input__country-list {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--rows-color, #ffffff);
    border: 1px solid var(--border-line-color, #e5e7eb);
    border-radius: 8px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    max-height: 300px;
    overflow: hidden;
    margin-top: 4px;
}

.phone-input__search {
    padding: 8px;
    border-bottom: 1px solid var(--border-line-color, #e5e7eb);
}

.phone-input__search-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border-line-color, #e5e7eb);
    border-radius: 6px;
    outline: none;
    font-size: 14px;
}

.phone-input__country-items {
    max-height: 250px;
    overflow-y: auto;
}

.phone-input__country-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.phone-input__country-item:hover {
    background: var(--sixth-color, #f9fafb);
}

.phone-input__country-name {
    flex: 1;
    font-size: 14px;
    color: var(--first-text-color, #1f2937);
}

.phone-input__country-code {
    font-size: 14px;
    color: var(--second-text-color, #6b7280);
    font-weight: 500;
}

body.dark .phone-input {
    background: transparent;
    border-bottom-color: var(--border-line-color, #374151);
}

body.dark .phone-input__country-selector {
    border-color: var(--border-line-color, #374151);
}

body.dark .phone-input__country-list {
    background: var(--rows-color, #1f2937);
    border-color: var(--border-line-color, #374151);
}

body.dark .phone-input__country-item:hover {
    background: var(--sixth-color, #111827);
}

/* Ensure error text appears correctly */
.phone-input-wrapper .form-error__text {
    display: block;
    margin-top: 0.5rem;
    font-size: 0.875rem;
    color: #ef4444;
}
</style>
