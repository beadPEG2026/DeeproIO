<div class="site-layout wrapper flex flex-col min-h-screen">

    <!-- thead start -->
    <header class="header-section header z-10 noselect" :class="{'mobile-body': isMobile}">
        <div class="header__row">
                <!-- header row left start -->
                <div class="header__row-left hidden-in-desktop">
                    <Link as="div" :href="route('profile.show')" class="left-header-icon">
                        <span class="header-dropdown__icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path class="header-dropdown__icon-color" d="M19 20.9375L18.8893 20.344C18.4165 17.809 18.1801 16.5415 17.3314 15.8473C16.4827 15.1531 15.0708 15.1745 12.2471 15.2174C12.1005 15.2197 11.9553 15.2208 11.8122 15.2208C11.7758 15.2208 11.7392 15.2208 11.7025 15.2206C8.68652 15.2082 7.17853 15.2021 6.31968 15.9687C5.46084 16.7353 5.31141 18.0979 5.01255 20.8231L5 20.9375"/>
                                <path class="header-dropdown__icon-color" d="M8.5 9.5625C8.5 7.6295 10.067 6.0625 12 6.0625V6.0625C13.933 6.0625 15.5 7.6295 15.5 9.5625V10.0625C15.5 11.7194 14.1569 13.0625 12.5 13.0625V13.0625H11.5V13.0625C9.84315 13.0625 8.5 11.7194 8.5 10.0625V9.5625Z"/>
                                <circle class="header-dropdown__icon-color" cx="12" cy="12" r="11.5"/>
                            </svg>
                        </span>
                    </Link>
                </div>
                <div class="header__row-left">
                    <!-- header logo start -->
                    <div class="header-logo">
                        <Link :href="route('home')">
                            <img class="main-logo" v-if="logo" :src="logo" />
                            <img class="main-logo" v-else :src="route('home') + '/images/logo.png'" />
                        </Link>
                    </div>

                    <!-- header nav start -->
                    <div :class="{'active': menuToggled}" class="header-nav">

                        <nav class="gxhead-nav" aria-label="Main navigation">
                            <ul class="gxhead-navlist">
                                <li class="gxhead-item gxhead-has-children">
                                    <a @click.prevent :class="['gxhead-link', { 'active': route().current('markets.*') }]" aria-haspopup="true" aria-expanded="false">{{ $t('Markets') }}</a>
                                    <ul class="gxhead-submenu" role="menu">
                                        <li class="gxhead-subitem">
                                            <Link :href="route('markets.spot')" :class="['gxhead-sublink', { 'active': route().current('markets.spot') }]" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                                                {{ $t('Spot') }}
                                            </Link>
                                        </li>
                                        <li class="gxhead-subitem">
                                            <Link :href="route('markets.futures')" :class="['gxhead-sublink', { 'active': route().current('markets.futures') }]" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                                                {{ $t('Futures') }}
                                            </Link>
                                        </li>
                                        <li class="gxhead-subitem">
                                            <Link :href="route('markets.options')" :class="['gxhead-sublink', { 'active': route().current('markets.options') }]" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line></svg>
                                                {{ $t('Options') }}
                                            </Link>
                                        </li>
                                    </ul>
                                </li>
                                <li class="gxhead-item gxhead-has-children">
                                    <a @click.prevent  :class="['gxhead-link', { 'active': route().current('swap') || route().current('p2p.*') || route().current('orders') }]" aria-haspopup="true" aria-expanded="false">{{ $t('Trade') }}</a>
                                    <ul class="gxhead-submenu" role="menu">
                                        <li class="gxhead-subitem">
                                            <Link :href="route('swap')" :class="['gxhead-sublink', { 'active': route().current('swap') }]" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                                                {{ $t('Swap') }}
                                            </Link>
                                        </li>
                                        <li class="gxhead-subitem">
                                            <Link :href="route('p2p.ads')" :class="['gxhead-sublink', { 'active': route().current('p2p.*') }]" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                                {{ $t('P2P') }}
                                            </Link>
                                        </li>
                                        <li class="gxhead-subitem">
                                            <Link :href="route('orders')" :class="['gxhead-sublink', { 'active': route().current('orders') }]" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                                {{ $t('Orders') }}
                                            </Link>
                                        </li>
                                        <li class="gxhead-subitem">
                                            <Link :href="route('reports.trades')" :class="['gxhead-sublink', { 'active': route().current('reports.trades') }]" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                                                {{ $t('Transactions') }}
                                            </Link>
                                        </li>
                                    </ul>
                                </li>
                                <li class="gxhead-item gxhead-has-children">
                                    <a @click.prevent  :class="['gxhead-link', { 'active': route().current('stakings') || route().current('launchpads') }]" aria-haspopup="true" aria-expanded="false">{{ $t('Earn') }}</a>
                                    <ul class="gxhead-submenu" role="menu">
                                         <li class="gxhead-subitem">
                                            <Link
                                                :href="route('stakings', { staking_type: 0 })"
                                                :class="[
                                                    'gxhead-sublink',
                                                    { 'active': route().current('stakings') && String(route().params.staking_type) === '0' }
                                                ]"
                                            >
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="10"></circle>
                                                    <path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"></path>
                                                    <line x1="12" y1="6" x2="12" y2="8"></line>
                                                    <line x1="12" y1="16" x2="12" y2="18"></line>
                                                </svg>
                                                {{ $t('Staking') }}
                                            </Link>
                                        </li>
                                        
                                        <li class="gxhead-subitem">
                                            <Link
                                                :href="route('stakings', { staking_type: 1 })"
                                                :class="[
                                                    'gxhead-sublink',
                                                    { 'active': route().current('stakings') && String(route().params.staking_type) === '1' }
                                                ]"
                                            >
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="10"></circle>
                                                    <path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"></path>
                                                    <line x1="12" y1="6" x2="12" y2="8"></line>
                                                    <line x1="12" y1="16" x2="12" y2="18"></line>
                                                </svg>
                                                {{ $t('Quantitative') }}
                                            </Link>
                                        </li>
                                        
                                        <li class="gxhead-subitem">
                                            <Link :href="route('launchpads')" :class="['gxhead-sublink', { 'active': route().current('launchpads') }]" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                                {{ $t('Launchpads') }}
                                            </Link>
                                        </li>
                                        <li v-if="$page.props.lending" class="gxhead-subitem">
                                            <Link :href="route('lendings')" :class="['gxhead-sublink', { 'active': route().current('lending') }]" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                                                {{ $t('Lending') }}
                                            </Link>
                                        </li>
                                    </ul>
                                </li>
                                <li class="gxhead-item gxhead-has-children">
                                    <a @click.prevent  :class="['gxhead-link', { 'active': route().current('articles') || route().current('articles.*') }]" aria-haspopup="true" aria-expanded="false">{{ $t('Resources') }}</a>
                                    <ul class="gxhead-submenu" role="menu">
                                        <li class="gxhead-subitem">
                                            <Link :href="route('articles')" class="gxhead-sublink" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                                                {{ $t('Articles') }}
                                            </Link>
                                        </li>
                                        <li class="gxhead-subitem">
                                            <Link :href="route('support')" class="gxhead-sublink" role="menuitem">
                                                <svg class="gxhead-sublink-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                                                {{ $t('Support Center') }}
                                            </Link>
                                        </li>
                                    </ul>
                                </li>
                                <li class="gxhead-item">
                                    <Link :href="route('wallets.new')" :class="['gxhead-link', { 'active': route().current('wallets.*') || route().current('wallets') }]">{{ $t('Wallets') }}</Link>
                                </li>
                                <li v-if="$page.props.unlim_status" class="gxhead-item">
                                    <a @click.prevent="showBuyCryptoModal" href="#" class="gxhead-link">{{ $t('Buy Crypto') }}</a>
                                </li>
                            </ul>
                        </nav>

                        <div class="mt-5" v-if="isMobile">
                            <div>
                                <div class="header-dropdown__row">
                                    <div class="header-dropdown__list" :class="{'active': true}">
                                        <div class="header-dropdown__list-info">
                                            <p v-if="$page.props.user" class="email">{{ $page.props.user.email ? $t($page.props.user.email) : $page.props.user.name }}</p>
                                            <p v-if="!$page.props.user" class="email">{{ $t('Guest') }}</p>
                                        </div>
                                        <Link v-if="!$page.props.user" as="a" :href="route('login')" class="settings-dropdown__list-link">
                                            <div class="header-dropdown__list-icon">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M18.14 21.6198C17.26 21.8798 16.22 21.9998 15 21.9998H8.99998C7.77998 21.9998 6.73999 21.8798 5.85999 21.6198C6.07999 19.0198 8.74998 16.9697 12 16.9697C15.25 16.9697 17.92 19.0198 18.14 21.6198Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M15 2H9C4 2 2 4 2 9V15C2 18.78 3.14 20.85 5.86 21.62C6.08 19.02 8.75 16.97 12 16.97C15.25 16.97 17.92 19.02 18.14 21.62C20.86 20.85 22 18.78 22 15V9C22 4 20 2 15 2ZM12 14.17C10.02 14.17 8.42 12.56 8.42 10.58C8.42 8.60002 10.02 7 12 7C13.98 7 15.58 8.60002 15.58 10.58C15.58 12.56 13.98 14.17 12 14.17Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M15.58 10.58C15.58 12.56 13.98 14.17 12 14.17C10.02 14.17 8.41998 12.56 8.41998 10.58C8.41998 8.60002 10.02 7 12 7C13.98 7 15.58 8.60002 15.58 10.58Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>
                                            <div class="header-dropdown__list-title">{{ $t('Login') }}</div>
                                        </Link>


                                        <Link v-if="$page.props.user" as="a" :href="route('profile.show')" class="settings-dropdown__list-link">
                                            <div class="header-dropdown__list-icon">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M18.14 21.6198C17.26 21.8798 16.22 21.9998 15 21.9998H8.99998C7.77998 21.9998 6.73999 21.8798 5.85999 21.6198C6.07999 19.0198 8.74998 16.9697 12 16.9697C15.25 16.9697 17.92 19.0198 18.14 21.6198Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M15 2H9C4 2 2 4 2 9V15C2 18.78 3.14 20.85 5.86 21.62C6.08 19.02 8.75 16.97 12 16.97C15.25 16.97 17.92 19.02 18.14 21.62C20.86 20.85 22 18.78 22 15V9C22 4 20 2 15 2ZM12 14.17C10.02 14.17 8.42 12.56 8.42 10.58C8.42 8.60002 10.02 7 12 7C13.98 7 15.58 8.60002 15.58 10.58C15.58 12.56 13.98 14.17 12 14.17Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M15.58 10.58C15.58 12.56 13.98 14.17 12 14.17C10.02 14.17 8.41998 12.56 8.41998 10.58C8.41998 8.60002 10.02 7 12 7C13.98 7 15.58 8.60002 15.58 10.58Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>
                                            <div class="header-dropdown__list-title">{{ $t('User Dashboard') }}</div>
                                        </Link>
                                        <Link as="a" :href="route('reports.referral-transactions')" class="settings-dropdown__list-link">
                                            <div class="header-dropdown__list-icon">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <circle cx="10" cy="8" r="3" stroke="white" stroke-width="1.5"/>
                                                    <path d="M4 20c0-3.5 3-6 6-6s6 2.5 6 6" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                                                    <path d="M16 6h4v4" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M20 6l-6 6" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                                                </svg>
                                            </div>
                                            <div class="header-dropdown__list-title">
                                                {{ $t('Referrals') }}
                                            </div>
                                        </Link>
                                        <Link as="a" :href="route('wallets')" class="settings-dropdown__list-link">
                                            <div class="header-dropdown__list-icon">
                                                <svg width="22" height="22" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <g clip-path="url(#clip0_1003_5963)">
                                                        <path class="header-dropdown__icon-color" d="M25.2409 4.95839C25.09 3.01066 23.458 1.47168 21.4723 1.47168H3.7812C1.69624 1.47168 0 3.16792 0 5.25288V22.7477C0 24.8326 1.69624 26.5289 3.7812 26.5289H24.2189C26.3038 26.5289 28.0001 24.8326 28.0001 22.7477V8.59806C28 6.86732 26.8307 5.40551 25.2409 4.95839ZM3.7812 3.23808H21.4724C22.4336 3.23808 23.2392 3.91489 23.4391 4.81691H3.7812C3.04092 4.81691 2.3502 5.03159 1.7664 5.40076V5.25288C1.7664 4.14193 2.67025 3.23808 3.7812 3.23808ZM24.2188 24.7625H3.7812C2.67025 24.7625 1.7664 23.8586 1.7664 22.7477V8.59806C1.7664 7.4871 2.67025 6.58326 3.7812 6.58326H24.2189C25.3298 6.58326 26.2337 7.4871 26.2337 8.59806V11.6803H20.6018C18.3701 11.6803 16.5544 13.496 16.5544 15.7278C16.5544 17.9595 18.3701 19.7752 20.6018 19.7752H26.2336V22.7477C26.2336 23.8586 25.3298 24.7625 24.2188 24.7625ZM26.2336 18.0088H20.6018C19.3441 18.0088 18.3208 16.9855 18.3208 15.7278C18.3208 14.47 19.3441 13.4467 20.6018 13.4467H26.2336V18.0088Z"/>
                                                        <path class="header-dropdown__icon-color" d="M20.9295 16.714C21.4374 16.714 21.8491 16.3024 21.8491 15.7945C21.8491 15.2867 21.4374 14.875 20.9295 14.875C20.4217 14.875 20.01 15.2867 20.01 15.7945C20.01 16.3024 20.4217 16.714 20.9295 16.714Z"/>
                                                    </g>
                                                    <defs>
                                                            <clipPath id="clip0_1003_5963">
                                                            <rect width="28" height="28" fill="white"/>
                                                        </clipPath>
                                                    </defs>
                                                </svg>
                                            </div>
                                            <div class="header-dropdown__list-title">{{ $t('Wallets') }}</div>
                                        </Link>

                                        <Link as="a" :href="route('orders')" class="settings-dropdown__list-link">
                                            <div class="header-dropdown__list-icon">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <g clip-path="url(#clip0_434_17062)">
                                                        <path d="M12.37 8.87988H17.62" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                        <path d="M6.38 8.87988L7.13 9.62988L9.38 7.37988" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                        <path d="M12.37 15.8799H17.62" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                        <path d="M6.38 15.8799L7.13 16.6299L9.38 14.3799" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                        <path d="M9 22H15C20 22 22 20 22 15V9C22 4 20 2 15 2H9C4 2 2 4 2 9V15C2 20 4 22 9 22Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </g>
                                                    <defs>
                                                        <clipPath id="clip0_434_17062">
                                                            <rect width="24" height="24" fill="white"/>
                                                        </clipPath>
                                                    </defs>
                                                </svg>
                                            </div>
                                            <div class="header-dropdown__list-title">{{ $t('Orders') }}</div>
                                        </Link>

                                        <Link as="a" :href="route('reports.trades')" class="settings-dropdown__list-link">
                                            <div class="header-dropdown__list-icon">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M6.72827 19.7C7.54827 18.82 8.79828 18.89 9.51828 19.85L10.5283 21.2C11.3383 22.27 12.6483 22.27 13.4583 21.2L14.4683 19.85C15.1883 18.89 16.4383 18.82 17.2583 19.7C19.0383 21.6 20.4883 20.97 20.4883 18.31V7.04C20.4883 3.01 19.5483 2 15.7683 2H8.20828C4.42828 2 3.48828 3.01 3.48828 7.04V18.3C3.49828 20.97 4.95827 21.59 6.72827 19.7Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M9.25 10H14.75" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>
                                            <div class="header-dropdown__list-title">{{ $t('Transactions') }}</div>
                                        </Link>

                                        <Link v-if="$page.props.user && $page.props.merchant" as="a" :href="route('merchant.dashboard')" class="settings-dropdown__list-link">
                                            <div class="header-dropdown__list-icon">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M3.01001 11.22V15.71C3.01001 20.2 4.81001 22 9.30001 22H14.69C19.18 22 20.98 20.2 20.98 15.71V11.22" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M12 12C13.83 12 15.18 10.51 15 8.68L14.34 2H9.67L9 8.68C8.82 10.51 10.17 12 12 12Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M18.31 12C20.33 12 21.81 10.36 21.61 8.35L21.33 5.6C20.97 3 19.97 2 17.35 2H14.3L15 9.01C15.17 10.66 16.66 12 18.31 12Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M5.64 12C7.29 12 8.78 10.66 8.94 9.01L9.16 6.8L9.64 2H6.59C3.97 2 2.97 3 2.61 5.6L2.34 8.35C2.14 10.36 3.62 12 5.64 12Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M12 17C10.33 17 9.5 17.83 9.5 19.5V22H14.5V19.5C14.5 17.83 13.67 17 12 17Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>
                                            <div class="header-dropdown__list-title">{{ $t('Merchant') }}</div>
                                        </Link>

                                        <Link v-if="$page.props.user && $page.props.user.admin" as="a" :href="route('admin.dashboard')" class="settings-dropdown__list-link">
                                            <div class="header-dropdown__list-icon">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M18.14 21.6198C17.26 21.8798 16.22 21.9998 15 21.9998H8.99998C7.77998 21.9998 6.73999 21.8798 5.85999 21.6198C6.07999 19.0198 8.74998 16.9697 12 16.9697C15.25 16.9697 17.92 19.0198 18.14 21.6198Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M15 2H9C4 2 2 4 2 9V15C2 18.78 3.14 20.85 5.86 21.62C6.08 19.02 8.75 16.97 12 16.97C15.25 16.97 17.92 19.02 18.14 21.62C20.86 20.85 22 18.78 22 15V9C22 4 20 2 15 2ZM12 14.17C10.02 14.17 8.42 12.56 8.42 10.58C8.42 8.60002 10.02 7 12 7C13.98 7 15.58 8.60002 15.58 10.58C15.58 12.56 13.98 14.17 12 14.17Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M15.58 10.58C15.58 12.56 13.98 14.17 12 14.17C10.02 14.17 8.41998 12.56 8.41998 10.58C8.41998 8.60002 10.02 7 12 7C13.98 7 15.58 8.60002 15.58 10.58Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>
                                            <div class="header-dropdown__list-title">{{ $t('Admin Dashboard') }}</div>
                                        </Link>
                                        <Link v-if="$page.props.user" as="button" :href="route('logout')" method="post" class="settings-dropdown__list-link">
                                            <div class="header-dropdown__list-icon logout-icon">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M17.44 14.62L20 12.06L17.44 9.5" stroke="white" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M9.76001 12.0596H19.93" stroke="white" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M11.76 20C7.34001 20 3.76001 17 3.76001 12C3.76001 7 7.34001 4 11.76 4" stroke="white" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>
                                            <div class="header-dropdown__list-title">{{ $t('Logout') }}</div>
                                        </Link>
                                    </div>
                                </div>
                            </div>

                            <div v-if="$page.props.lang_mode_enabled" class="theme-switcher">
                                <h3 class="pt-5 pb-5">{{ $t('Switch language:') }}</h3>
                                <div class="header-dropdown__list" :class="{'active': true}">
                                    <language-switcher></language-switcher>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- header nav end -->

                </div>
                <!-- header row left end -->

            <!-- header section navigation right start -->
            <div class="header__row-right">
                <!-- header btn start -->
                <div @click.stop="menuToggled = !menuToggled" class="header-btn mt-2">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <!-- header btn end -->

                <!-- header button start -->
                <div v-if="!$page.props.user && !isMobile" class="header-dropdown__button">
                            <span class="header-dropdown__icon">
                                <Link :href="route('login')">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path class="header-dropdown__icon-color" d="M19 20.9375L18.8893 20.344C18.4165 17.809 18.1801 16.5415 17.3314 15.8473C16.4827 15.1531 15.0708 15.1745 12.2471 15.2174C12.1005 15.2197 11.9553 15.2208 11.8122 15.2208C11.7758 15.2208 11.7392 15.2208 11.7025 15.2206C8.68652 15.2082 7.17853 15.2021 6.31968 15.9687C5.46084 16.7353 5.31141 18.0979 5.01255 20.8231L5 20.9375"/>
                                        <path class="header-dropdown__icon-color" d="M8.5 9.5625C8.5 7.6295 10.067 6.0625 12 6.0625V6.0625C13.933 6.0625 15.5 7.6295 15.5 9.5625V10.0625C15.5 11.7194 14.1569 13.0625 12.5 13.0625V13.0625H11.5V13.0625C9.84315 13.0625 8.5 11.7194 8.5 10.0625V9.5625Z"/>
                                        <circle class="header-dropdown__icon-color" cx="12" cy="12" r="11.5"/>
                                    </svg>
                                    </Link>
                            </span>
                </div>
                <!-- header button end -->

                <!-- header dropdown start -->
                <div v-if="$page.props.user && !isMobile"
                     @mouseover="isMobile ? null : showingUserProfileDropdown = true"
                     @mouseleave="showingUserProfileDropdown = false"
                     @click.stop.prevent="showingUserProfileDropdown = !showingUserProfileDropdown"
                     class="header-dropdown">
                    <div class="header-dropdown__row">
                        <!-- header dropdown button start -->
                        <div class="header-dropdown__button">
                                <span class="header-dropdown__icon">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path class="header-dropdown__icon-color" d="M19 20.9375L18.8893 20.344C18.4165 17.809 18.1801 16.5415 17.3314 15.8473C16.4827 15.1531 15.0708 15.1745 12.2471 15.2174C12.1005 15.2197 11.9553 15.2208 11.8122 15.2208C11.7758 15.2208 11.7392 15.2208 11.7025 15.2206C8.68652 15.2082 7.17853 15.2021 6.31968 15.9687C5.46084 16.7353 5.31141 18.0979 5.01255 20.8231L5 20.9375"/>
                                        <path class="header-dropdown__icon-color" d="M8.5 9.5625C8.5 7.6295 10.067 6.0625 12 6.0625V6.0625C13.933 6.0625 15.5 7.6295 15.5 9.5625V10.0625C15.5 11.7194 14.1569 13.0625 12.5 13.0625V13.0625H11.5V13.0625C9.84315 13.0625 8.5 11.7194 8.5 10.0625V9.5625Z"/>
                                        <circle class="header-dropdown__icon-color" cx="12" cy="12" r="11.5"/>
                                    </svg>
                                </span>
                        </div>
                        <!-- header dropdown button end -->
                        <!-- header dropdown list start -->
                        <div class="header-dropdown__list" :class="{'active': showingUserProfileDropdown}">
                            <div class="header-dropdown__list-info">
                                <p>{{ $t('User:') }}</p>
                                <p class="email">{{ $page.props.user.email ? $t($page.props.user.email) : $page.props.user.name }}</p>
                            </div>
                            <Link as="a" :href="route('profile.show')" class="settings-dropdown__list-link">
                                <div class="header-dropdown__list-icon">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path class="header-dropdown__list-color" d="M4.40334 17.3222C4.44 16.9879 4.45832 16.8208 4.48127 16.7015C4.75983 15.2543 5.93043 14.2799 7.40422 14.2686C7.52566 14.2677 7.71526 14.2816 8.09447 14.3095C9.27227 14.3959 10.599 14.4667 11.7854 14.4667C13.0237 14.4667 14.3969 14.3896 15.5907 14.298C15.9564 14.27 16.1392 14.256 16.2581 14.2563C17.6545 14.2601 18.7825 15.1264 19.1463 16.4745C19.1773 16.5893 19.2069 16.748 19.2661 17.0653V17.0653C19.3314 17.4153 19.364 17.5902 19.3767 17.7324C19.524 19.3759 18.3162 20.8298 16.6735 20.9865C16.5314 21 16.3534 21 15.9975 21L11.7854 21H7.69989C7.37599 21 7.21405 21 7.08163 20.9882C5.50997 20.8487 4.31503 19.5155 4.34763 17.938C4.35038 17.8051 4.36803 17.6441 4.40334 17.3222V17.3222Z"/>
                                        <path class="header-dropdown__list-color" d="M8 7C8 4.79086 9.79086 3 12 3V3C14.2091 3 16 4.79086 16 7V7.57143C16 9.46498 14.465 11 12.5714 11V11H11.4286V11C9.53502 11 8 9.46498 8 7.57143V7Z"/>
                                    </svg>
                                </div>
                                <div class="header-dropdown__list-title">{{ $t('User Dashboard') }}</div>
                            </Link>
                            <Link as="a" :href="route('reports.referral-transactions')" class="settings-dropdown__list-link">
                                            <div class="header-dropdown__list-icon">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <circle cx="10" cy="8" r="3" stroke="white" stroke-width="1.5"/>
                                                    <path d="M4 20c0-3.5 3-6 6-6s6 2.5 6 6" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                                                    <path d="M16 6h4v4" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M20 6l-6 6" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                                                </svg>
                                            </div>
                                            <div class="header-dropdown__list-title">
                                                {{ $t('Referrals') }}
                                            </div>
                            </Link>
                            <Link as="a" :href="route('orders')" class="settings-dropdown__list-link">
                                <div class="header-dropdown__list-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g clip-path="url(#clip0_434_17062)">
                                            <path d="M12.37 8.87988H17.62" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M6.38 8.87988L7.13 9.62988L9.38 7.37988" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M12.37 15.8799H17.62" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M6.38 15.8799L7.13 16.6299L9.38 14.3799" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M9 22H15C20 22 22 20 22 15V9C22 4 20 2 15 2H9C4 2 2 4 2 9V15C2 20 4 22 9 22Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0_434_17062">
                                                <rect width="24" height="24" fill="white"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </div>
                                <div class="header-dropdown__list-title">{{ $t('Orders') }}</div>
                            </Link>

                            <Link as="a" :href="route('reports.trades')" class="settings-dropdown__list-link">
                                <div class="header-dropdown__list-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M6.72827 19.7C7.54827 18.82 8.79828 18.89 9.51828 19.85L10.5283 21.2C11.3383 22.27 12.6483 22.27 13.4583 21.2L14.4683 19.85C15.1883 18.89 16.4383 18.82 17.2583 19.7C19.0383 21.6 20.4883 20.97 20.4883 18.31V7.04C20.4883 3.01 19.5483 2 15.7683 2H8.20828C4.42828 2 3.48828 3.01 3.48828 7.04V18.3C3.49828 20.97 4.95827 21.59 6.72827 19.7Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M9.25 10H14.75" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="header-dropdown__list-title">{{ $t('Transactions') }}</div>
                            </Link>

                            <Link as="a" v-if="$page.props.merchant" :href="route('merchant.dashboard')" class="settings-dropdown__list-link">
                                <div class="header-dropdown__list-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M3.01001 11.22V15.71C3.01001 20.2 4.81001 22 9.30001 22H14.69C19.18 22 20.98 20.2 20.98 15.71V11.22" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M12 12C13.83 12 15.18 10.51 15 8.68L14.34 2H9.67L9 8.68C8.82 10.51 10.17 12 12 12Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M18.31 12C20.33 12 21.81 10.36 21.61 8.35L21.33 5.6C20.97 3 19.97 2 17.35 2H14.3L15 9.01C15.17 10.66 16.66 12 18.31 12Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M5.64 12C7.29 12 8.78 10.66 8.94 9.01L9.16 6.8L9.64 2H6.59C3.97 2 2.97 3 2.61 5.6L2.34 8.35C2.14 10.36 3.62 12 5.64 12Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M12 17C10.33 17 9.5 17.83 9.5 19.5V22H14.5V19.5C14.5 17.83 13.67 17 12 17Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="header-dropdown__list-title">{{ $t('Merchant') }}</div>
                            </Link>

                            <Link v-if="$page.props.user && $page.props.user.admin" as="a" :href="route('admin.dashboard')" class="settings-dropdown__list-link">
                                <div class="header-dropdown__list-icon">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M17.9718 11.6855H6.65753C5.96323 11.6855 5.40039 12.2484 5.40039 12.9427V20.4855C5.40039 21.1798 5.96323 21.7427 6.65753 21.7427H17.9718C18.6661 21.7427 19.229 21.1798 19.229 20.4855V12.9427C19.229 12.2484 18.6661 11.6855 17.9718 11.6855Z" class="header-dropdown__list-color"/>
                                        <path d="M16.338 11.6855L8.29232 11.6855C8.02559 11.6855 7.76978 11.5825 7.58118 11.3992C7.39257 11.2158 7.28661 10.9671 7.28661 10.7078L7.28661 7.77444C7.28661 6.47782 7.8164 5.23432 8.75944 4.31747C9.70248 3.40063 10.9815 2.88555 12.3152 2.88555C12.9755 2.88555 13.6294 3.012 14.2395 3.25769C14.8496 3.50338 15.404 3.8635 15.8709 4.31747C16.814 5.23432 17.3437 6.47782 17.3437 7.77444L17.3437 10.7078C17.3437 10.9671 17.2378 11.2158 17.0492 11.3992C16.8606 11.5825 16.6048 11.6855 16.338 11.6855V11.6855Z" class="header-dropdown__list-color"/>
                                        <path d="M12.3145 17.3438L12.3145 19.2295" class="header-dropdown__list-color"/>
                                        <path d="M12.3145 16.0859L12.3145 16.7145" class="header-dropdown__list-color"/>
                                    </svg>
                                </div>
                                <div class="header-dropdown__list-title">{{ $t('Admin Dashboard') }}</div>
                            </Link>

                            <Link as="button" :href="route('logout')" method="post" class="settings-dropdown__list-link">
                                <div class="header-dropdown__list-icon logout-icon">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M9 17H5.66667C5.22464 17 4.80072 16.8361 4.48816 16.5444C4.17559 16.2527 4 15.857 4 15.4444V4.55556C4 4.143 4.17559 3.74733 4.48816 3.45561C4.80072 3.16389 5.22464 3 5.66667 3H9" class="header-dropdown__list-color"/>
                                        <path d="M14 14L18 10L14 6" class="header-dropdown__list-color"/>
                                        <path d="M18 10H9" class="header-dropdown__list-color"/>
                                    </svg>
                                </div>
                                <div class="header-dropdown__list-title">{{ $t('Logout') }}</div>
                            </Link>
                        </div>
                        <!-- header dropdown list end -->
                    </div>
                </div>

                <div v-if="!isMobile && $page.props.lang_mode_enabled"
                    @mouseover="isMobile ? null : showingLanguageDropdown = true"
                    @mouseleave="showingLanguageDropdown = false"
                    @click.stop.prevent="showingLanguageDropdown = !showingLanguageDropdown"
                    class="header-dropdown language-dropdown">
                    <div class="header-dropdown__row">
                        <!-- header dropdown button start -->
                        <div class="header-dropdown__button">
                                <span class="header-dropdown__icon">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M12 23C18.0751 23 23 18.0751 23 12C23 5.92487 18.0751 1 12 1C5.92487 1 1 5.92487 1 12C1 18.0751 5.92487 23 12 23Z" class="header-dropdown__icon-color"/>
                                        <path d="M1 12H23" class="header-dropdown__icon-color"/>
                                        <path d="M12 1C14.5013 4.01219 15.9228 7.92124 16 12C15.9228 16.0788 14.5013 19.9878 12 23C9.49872 19.9878 8.07725 16.0788 8 12C8.07725 7.92124 9.49872 4.01219 12 1V1Z" class="header-dropdown__icon-color"/>
                                    </svg>
                                </span>
                        </div>
                        <!-- header dropdown button end -->
                        <!-- header dropdown list start -->
                        <div class="header-dropdown__list" :class="{'active': showingLanguageDropdown}">
                            <language-switcher></language-switcher>
                        </div>
                        <!-- header dropdown list end -->
                    </div>
                </div>

                <!-- mode start -->
                <theme-mode v-if="$page.props.theme_mode_enabled"></theme-mode>
                <!-- mode end -->
            </div>
            <!-- header section navigation right end -->
        </div>
    </header>
    <!-- thead end -->

    <!-- MAIN START -->
    <main class="main-section main flex-grow" :class="{'header-main': isHomePage}">

        <div class="main__system-messages">
            <socket />
        </div>

        <div class="main__container">
            <div :class="{'max-w-screen-2xl mx-auto': true, 'mobile-body': isMobile}">
                <slot></slot>
            </div>
        </div>

        <!-- Modal Portal -->
        <div :class="{'mobile-body': isMobile}">
            <portal-target name="modal" multiple></portal-target>
        </div>
    </main>
    <!-- MAIN END -->

    <!-- section footer start -->
    <footer class="noselect section-footer hidden-in-mobile" :class="{'mobile-body': isMobile, 'not-home-page': !isHomePage}">
        <div :class="{'max-w-screen-2xl mx-auto': true}">

            <!-- banner start -->
            <div v-if="isHomePage" class="banner">
                <h2>{{ $t('Embark on Your Crypto Journey Today!') }}</h2>
                <div class="banner-text">
                    <p>{{ $t('Our platform makes buying and selling cryptocurrency simple and effortless. Create your account and start trading today.') }}</p>

                    <Link v-if="$page.props.user" :href="route('markets')">{{ $t('Get Access') }}</Link>
                    <Link v-if="!$page.props.user" :href="route('register')">{{ $t('Get Access') }}</Link>
                </div>
            </div>
            <!-- banner end -->
            <!-- tfood start -->
            <div v-if="isHomePage" class="footer">
                <div class="footer-left">
                    <span class="footer-left__logo text-white font-extrabold text-2xl">
                        <img class="main-logo cssUqkHAlmF" v-if="logo" :src="logo" />
                        <img class="main-logo" v-else :src="route('home') + '/images/logo.png'" />
                    </span>
                </div>
                <div class="footer-right">
                    <div class="footer-right__navigation">
                        <div class="footer-right__item">
                            <h4>{{ $t('Contact') }}</h4>

                            <ul>
                                <li><a :href="route('page.show', 'about')">{{ $t('About') }}</a></li>
                                <li><a :href="route('page.show', 'contacts')">{{ $t('Contacts') }}</a></li>
                                <li><a :href="route('support')">{{ $t('Support Center') }}</a></li>
                                <li><a :href="route('page.show', 'referrals')">{{ $t('Referrals') }}</a></li>
                            </ul>
                        </div>
                        <div class="footer-right__item">
                            <h4>{{ $t('Exchange') }}</h4>

                            <ul>
                                <li><a :href="route('markets')">{{ $t('Markets') }}</a></li>
                                <li><a :href="route('page.show', 'trading-rules')">{{ $t('Trading Rules') }}</a></li>
                                <li><a target="_blank" href="/docs/api">{{ $t('API Documentation') }}</a></li>
                                <li><a :href="route('page.show', 'fees')">{{ $t('Fees') }}</a></li>
                            </ul>
                        </div>
                        <div class="footer-right__item">
                            <h4>{{ $t('Legal') }}</h4>

                            <ul>
                                <li><a :href="route('page.show', 'terms')">{{ $t('Terms of Use') }}</a></li>
                                <li><a :href="route('page.show', 'privacy-gdpr')">{{ $t('GDPR') }}</a></li>
                                <li><a :href="route('page.show', 'disclosure')">{{ $t('Disclosure Statement') }}</a></li>
                            </ul>
                        </div>
                    </div>
                    <div v-if="$page.props.social" class="footer-right__social social">
                        <h4>{{ $t('Community') }}</h4>
                        <nav>
                            <a v-if="$page.props.social.facebook" :href="$page.props.social.facebook">
                                <span class="social-icon" name="facebook">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M341.269 85.0133H388.011V3.60533C379.947 2.496 352.213 0 319.915 0C252.523 0 206.357 42.3893 206.357 120.299V192H131.989V283.008H206.357V512H297.536V283.029H368.896L380.224 192.021H297.515V129.323C297.536 103.019 304.619 85.0133 341.269 85.0133V85.0133Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.twitter" :href="$page.props.social.twitter">
                                <span class="social-icon" name="twitter">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M459.392 151.744C480.213 136.96 497.728 118.507 512 97.2587V97.2373C492.949 105.579 472.683 111.125 451.52 113.813C473.28 100.821 489.899 80.4053 497.707 55.808C477.419 67.904 455.019 76.4373 431.147 81.216C411.883 60.6933 384.427 48 354.475 48C296.363 48 249.579 95.168 249.579 152.981C249.579 161.301 250.283 169.301 252.011 176.917C164.757 172.651 87.5307 130.837 35.648 67.1147C26.6027 82.8373 21.2693 100.821 21.2693 120.171C21.2693 156.523 39.9787 188.736 67.904 207.403C51.0293 207.083 34.496 202.176 20.48 194.475V195.627C20.48 246.635 56.8533 289.003 104.576 298.773C96.0213 301.12 86.72 302.229 77.056 302.229C70.336 302.229 63.552 301.845 57.1947 300.437C70.784 341.995 109.397 372.565 155.264 373.568C119.552 401.493 74.1973 418.325 25.1093 418.325C16.512 418.325 8.256 417.941 0 416.896C46.5067 446.869 101.589 464 161.024 464C346.261 464 466.987 309.461 459.392 151.744V151.744Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.telegram" :href="$page.props.social.telegram">
                                <span class="social-icon" name="telegram">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M200.896 323.862L192.427 442.987C204.544 442.987 209.792 437.782 216.085 431.531L272.896 377.238L390.613 463.446C412.203 475.478 427.413 469.142 433.237 443.584L510.507 81.5149L510.528 81.4935C517.376 49.5789 498.987 37.0989 477.952 44.9282L23.7652 218.816C-7.23211 230.848 -6.76278 248.128 18.4959 255.958L134.613 292.075L404.331 123.307C417.024 114.902 428.565 119.552 419.072 127.958L200.896 323.862Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.coinmarketcap" :href="$page.props.social.coinmarketcap">
                                <span class="social-icon" name="coinmarketcap">
                                    <svg viewBox="0 0 510 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                        <path d="M443.484 305.934C439.447 308.685 434.735 310.309 429.844 310.633C424.953 310.958 420.064 309.973 415.691 307.78C405.427 302.045 399.895 288.597 399.895 270.272V214.108C399.895 187.147 389.098 167.964 371.036 162.756C340.511 153.923 317.717 190.904 308.919 204.813L254.933 291.234V185.762C254.334 161.438 246.336 146.87 231.206 142.519C221.209 139.618 206.213 140.805 191.617 162.822L70.9148 354.451C54.8549 324.062 46.5278 290.257 46.6545 255.967C46.6545 140.475 139.963 46.5395 254.933 46.5395C369.903 46.5395 463.546 140.475 463.546 255.967V256.56C463.546 256.56 463.546 256.956 463.546 257.154C464.679 279.5 457.347 297.299 443.551 305.934H443.484ZM510.133 256.033V254.912C509.2 114.173 395.097 0 254.933 0C114.77 0 0 114.833 0 255.967C0 397.102 114.37 512 254.933 512C319.402 511.997 381.417 487.557 428.221 443.707C432.764 439.474 435.438 433.64 435.662 427.471C435.887 421.301 433.645 415.293 429.421 410.747C427.4 408.536 424.957 406.742 422.232 405.468C419.507 404.195 416.555 403.466 413.544 403.325C410.534 403.184 407.525 403.634 404.691 404.647C401.856 405.661 399.253 407.218 397.03 409.231C376.879 428.136 353.074 442.819 327.043 452.398C301.012 461.977 273.292 466.255 245.548 464.974C217.804 463.694 190.608 456.881 165.592 444.946C140.576 433.011 118.257 416.199 99.9739 395.52L208.612 222.809V302.507C208.612 340.806 223.608 353.199 236.205 356.825C248.802 360.45 268.063 357.945 288.258 325.513L348.242 229.467C350.108 226.369 351.908 223.732 353.508 221.425V270.272C353.508 306.066 368.037 334.675 393.497 348.782C405.074 354.964 418.12 357.95 431.265 357.428C444.411 356.906 457.172 352.895 468.211 345.816C496.204 327.82 511.466 295.255 509.867 256.033H510.133Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="510" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.discord" :href="$page.props.social.discord">
                                <span class="social-icon" name="discord">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M76.373 452.181H380.565L366.037 405.141L400.789 434.965L432.64 463.509L490.666 512V52.8C489.216 24.256 464.64 0 434.176 0L76.4797 0.064C46.037 0.064 21.333 24.3627 21.333 52.9067V399.36C21.333 429.461 45.9943 452.181 76.373 452.181V452.181ZM301.397 121.237L300.693 121.493L300.949 121.237H301.397ZM138.602 148.309C177.706 119.851 213.952 121.216 213.952 121.216L216.874 124.096C169.024 135.509 147.328 156.885 147.328 156.885C147.328 156.885 153.13 154.048 163.264 149.717C227.712 124.373 298.09 126.208 363.242 158.293C363.242 158.293 341.504 138.304 296.597 125.504L300.565 121.6C306.773 121.621 339.626 122.773 374.784 148.48C374.784 148.48 414.122 215.68 414.122 298.24C412.821 296.661 389.717 333.781 330.261 335.061C330.261 335.061 320.192 323.669 313.024 313.728C347.797 303.744 360.81 283.776 360.81 283.776C349.397 290.965 338.986 295.232 330.538 299.499C317.546 305.216 304.533 308.032 291.541 310.912C230.016 320.896 195.477 304.192 162.837 290.944L151.68 285.269C151.68 285.269 164.672 305.237 198.058 315.221C189.29 325.227 180.608 336.597 180.608 336.597C121.173 335.189 99.4983 298.069 99.4983 298.069C99.4983 215.381 138.602 148.309 138.602 148.309V148.309Z" class="social-icon__color"/>
                                            <path d="M305.238 272.448C320.406 272.448 332.758 259.648 332.758 243.861C332.758 228.181 320.47 215.381 305.238 215.381V215.445C290.134 215.445 277.76 228.203 277.718 243.989C277.718 259.648 290.07 272.448 305.238 272.448Z" class="social-icon__color"/>
                                            <path d="M206.72 272.448C221.888 272.448 234.24 259.648 234.24 243.861C234.24 228.181 221.974 215.381 206.806 215.381L206.72 215.445C191.552 215.445 179.2 228.203 179.2 243.989C179.2 259.648 191.552 272.448 206.72 272.448V272.448Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.github" :href="$page.props.social.github">
                                <span class="social-icon" name="github">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M256 10.6665C114.56 10.6665 0 123.307 0 262.229C0 373.397 73.344 467.669 175.04 500.907C187.84 503.275 192.533 495.488 192.533 488.811C192.533 482.837 192.32 467.008 192.213 446.037C121.003 461.205 105.984 412.288 105.984 412.288C94.336 383.253 77.504 375.488 77.504 375.488C54.3147 359.893 79.296 360.213 79.296 360.213C105.003 361.963 118.507 386.133 118.507 386.133C141.333 424.597 178.432 413.483 193.067 407.061C195.371 390.784 201.963 379.712 209.28 373.419C152.427 367.125 92.672 345.493 92.672 249.109C92.672 221.653 102.592 199.21 119.019 181.61C116.139 175.253 107.499 149.675 121.259 115.029C121.259 115.029 142.699 108.288 191.659 140.821C212.139 135.232 233.899 132.459 255.659 132.331C277.419 132.459 299.179 135.232 319.659 140.821C368.299 108.288 389.739 115.029 389.739 115.029C403.499 149.675 394.859 175.253 392.299 181.61C408.619 199.21 418.539 221.653 418.539 249.109C418.539 345.749 358.699 367.019 301.739 373.205C310.699 380.757 319.019 396.181 319.019 419.755C319.019 453.419 318.699 480.469 318.699 488.64C318.699 495.232 323.179 503.104 336.299 500.587C438.72 467.563 512 373.227 512 262.229C512 123.307 397.376 10.6665 256 10.6665V10.6665Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.instagram" :href="$page.props.social.instagram">
                                <span class="social-icon" name="instagram">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M256.086 124.544C183.488 124.544 124.715 183.381 124.715 255.915C124.715 328.512 183.552 387.285 256.086 387.285C328.683 387.285 387.456 328.448 387.456 255.915C387.456 183.317 328.619 124.544 256.086 124.544V124.544ZM256.086 341.184C208.96 341.184 170.816 303.019 170.816 255.915C170.816 208.811 208.982 170.645 256.086 170.645C303.19 170.645 341.355 208.811 341.355 255.915C341.376 303.019 303.211 341.184 256.086 341.184V341.184Z" class="social-icon__color"/>
                                            <path d="M361.557 1.62094C314.453 -0.576389 197.781 -0.469723 150.635 1.62094C109.205 3.56228 72.6613 13.5676 43.2 43.0289C-6.03737 92.2663 0.255965 158.613 0.255965 255.914C0.255965 355.498 -5.2907 420.309 43.2 468.8C92.6293 518.208 159.936 511.744 256.085 511.744C354.731 511.744 388.779 511.808 423.659 498.304C471.083 479.893 506.88 437.504 510.379 361.365C512.597 314.24 512.469 197.589 510.379 150.442C506.155 60.565 457.92 6.05828 361.557 1.62094V1.62094ZM436.117 436.224C403.84 468.501 359.061 465.621 255.467 465.621C148.8 465.621 106.027 467.2 74.816 435.904C38.8693 400.128 45.376 342.677 45.376 255.573C45.376 137.706 33.28 52.8209 151.573 46.7623C178.752 45.8023 186.752 45.4823 255.168 45.4823L256.128 46.1223C369.813 46.1223 459.008 34.2183 464.363 152.49C465.579 179.477 465.856 187.584 465.856 255.893C465.835 361.322 467.84 404.352 436.117 436.224V436.224Z" class="social-icon__color"/>
                                            <path d="M392.662 150.058C409.616 150.058 423.36 136.314 423.36 119.36C423.36 102.405 409.616 88.6611 392.662 88.6611C375.707 88.6611 361.963 102.405 361.963 119.36C361.963 136.314 375.707 150.058 392.662 150.058Z" fill="black"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.linkedin" :href="$page.props.social.linkedin">
                                <span class="social-icon" name="linkedin">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M511.872 512V511.979H512V324.203C512 232.342 492.224 161.579 384.832 161.579C333.205 161.579 298.56 189.91 284.416 216.768H282.923V170.155H181.099V511.979H287.125V342.72C287.125 298.155 295.573 255.062 350.763 255.062C405.141 255.062 405.952 305.92 405.952 345.579V512H511.872Z" class="social-icon__color"/>
                                            <path d="M8.44824 170.176H114.603V512H8.44824V170.176Z" class="social-icon__color"/>
                                            <path d="M61.4827 0C27.5413 0 0 27.5413 0 61.4827C0 95.424 27.5413 123.541 61.4827 123.541C95.424 123.541 122.965 95.424 122.965 61.4827C122.944 27.5413 95.4027 0 61.4827 0V0Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.reddit" :href="$page.props.social.reddit">
                                <span class="social-icon" name="reddit">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M454.934 198.571C438.763 198.571 424.534 205.376 414.059 215.979C375.552 188.928 323.627 171.52 266.091 169.664L295.979 33.003L391.147 54.6777C391.147 78.315 410.133 97.6217 433.429 97.6217C457.173 97.6217 476.267 77.803 476.267 54.1443C476.267 30.4857 457.302 10.667 433.451 10.667C416.832 10.667 402.496 20.843 395.349 34.7523L290.24 11.115C284.949 9.68566 279.766 13.5257 278.358 18.9017L245.547 169.558C188.459 171.968 137.088 189.355 98.4535 216.427C87.9788 205.376 73.1948 198.571 57.0242 198.571C-2.96516 198.571 -22.6132 280.256 32.3202 308.182C30.3788 316.822 29.5042 326.038 29.5042 335.232C29.5042 426.987 131.371 501.334 256.448 501.334C382.059 501.334 483.926 426.987 483.926 335.232C483.926 326.038 482.966 316.395 480.598 307.734C534.422 279.702 514.603 198.614 454.934 198.571V198.571ZM119.488 313.984C119.488 289.899 138.475 270.507 162.326 270.507C185.622 270.507 204.63 289.771 204.63 313.984C204.63 337.643 185.643 356.928 162.326 356.928C138.582 357.035 119.488 337.643 119.488 313.984V313.984ZM350.72 416.342C311.446 456.192 200.619 456.192 161.323 416.342C156.992 412.502 156.992 405.718 161.323 401.344C165.099 397.504 171.797 397.504 175.573 401.344C205.568 432.555 305.067 433.088 336.363 401.344C340.139 397.504 346.837 397.504 350.613 401.344C355.029 405.739 355.03 412.523 350.72 416.342V416.342ZM349.846 357.014C326.55 357.014 307.563 337.75 307.563 314.112C307.563 290.027 326.55 270.635 349.846 270.635C373.59 270.635 392.683 289.899 392.683 314.112C392.576 337.643 373.59 357.014 349.846 357.014Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.vk" :href="$page.props.social.vk">
                                <span class="social-icon" name="vk">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M424.853 277.931C416.576 267.477 418.944 262.827 424.853 253.483C424.96 253.376 493.291 158.955 500.331 126.933L500.373 126.912C503.872 115.243 500.373 106.667 483.456 106.667H427.477C413.227 106.667 406.656 114.027 403.136 122.261C403.136 122.261 374.635 190.485 334.315 234.709C321.301 247.488 315.285 251.584 308.181 251.584C304.683 251.584 299.243 247.488 299.243 235.819V126.912C299.243 112.917 295.253 106.667 283.456 106.667H195.435C186.496 106.667 181.184 113.195 181.184 119.275C181.184 132.544 201.344 135.595 203.435 172.928V253.931C203.435 271.68 200.213 274.944 193.067 274.944C174.037 274.944 127.851 206.443 100.48 128.043C94.9547 112.832 89.5573 106.688 75.2 106.688H19.2C3.22133 106.688 0 114.048 0 122.283C0 136.832 19.0293 209.173 88.4907 304.747C134.784 369.984 199.979 405.333 259.285 405.333C294.933 405.333 299.285 397.483 299.285 383.979C299.285 321.643 296.064 315.755 313.92 315.755C322.197 315.755 336.448 319.851 369.728 351.317C407.765 388.629 414.016 405.333 435.307 405.333H491.285C507.243 405.333 515.328 397.483 510.677 381.995C500.032 349.419 428.096 282.411 424.853 277.931V277.931Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.youtube" :href="$page.props.social.youtube">
                                <span class="social-icon" name="youtube">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M500.672 126.485L501.312 130.666C495.126 108.714 478.422 91.7756 457.195 85.6103L456.747 85.5036C416.832 74.6663 256.214 74.6663 256.214 74.6663C256.214 74.6663 96.0004 74.453 55.6804 85.5036C34.0484 91.7756 17.3231 108.714 11.2431 130.218L11.1364 130.666C-3.77558 208.554 -3.88225 302.144 11.7978 385.536L11.1364 381.312C17.3231 403.264 34.0271 420.202 55.2538 426.368L55.7018 426.474C95.5738 437.333 256.235 437.333 256.235 437.333C256.235 437.333 416.427 437.333 456.768 426.474C478.422 420.202 495.147 403.264 501.227 381.76L501.334 381.312C508.118 345.088 512 303.402 512 260.821C512 259.264 512 257.685 511.979 256.106C512 254.656 512 252.928 512 251.2C512 208.597 508.118 166.912 500.672 126.485V126.485ZM204.971 333.888V178.304L338.646 256.213L204.971 333.888Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                            <a v-if="$page.props.social.medium" :href="$page.props.social.medium">
                                <span class="social-icon" name="medium">
                                    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M471.147 100.97L512 61.8877V53.333H370.475L269.611 304.384L154.859 53.333H6.464V61.8877L54.1867 119.338C58.8373 123.584 61.2693 129.792 60.6507 136.042V361.813C62.1227 369.941 59.4773 378.304 53.76 384.213L0 449.365V457.813H152.427V449.258L98.6667 384.213C92.8427 378.282 90.0907 370.069 91.264 361.813V166.528L225.067 457.92H240.619L355.669 166.528V398.656C355.669 404.778 355.669 406.037 351.659 410.048L310.272 450.09V458.666H511.061V450.112L471.168 411.05C467.669 408.405 465.856 403.968 466.603 399.658V112.362C465.856 108.032 467.648 103.594 471.147 100.97V100.97Z" class="social-icon__color"/>
                                        </g>
                                        <defs>
                                            <clipPath id="clip0">
                                                <rect width="512" height="512" class="social-icon__color"/>
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                            </a>
                        </nav>
                    </div>
                </div>
            </div>
            <!-- tfood end -->

            <!-- copyright start -->
            <div class="copyright">
                <div class="copyright-left">
                    <p>{{ $page.props.siteName }} {{ $t('©') }} {{ currentYear }}</p>
                </div>
                <div class="copyright-right">
                    <span>
                        <font-awesome-icon class="mr-2" icon="clock"></font-awesome-icon>
                        {{ time }}
                    </span>
                    <span>
                        <font-awesome-icon class="online-status-icon" :class="{'text-green-300': $page.props.live_conenction, 'text-red-500': !$page.props.live_conenction}" icon="circle"></font-awesome-icon>
                        {{ $page.props.live_conenction ? $t('Stable connection') : $t('Server Maintenance') }}
                    </span>
                </div>
            </div>
            <!-- copyright end -->

        </div>
    </footer>

    <bottom-menu v-if="isMobile"></bottom-menu>
    <on-ramp-swap v-if="$page.props.unlim_status"></on-ramp-swap>
</div>
