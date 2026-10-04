<app-layout>
    <Head>
        <title>{{ $t('Home') }}</title>
        <meta name="description" content="">
    </Head>

    <div class="cex-home">
        <!-- Hero Section -->
        <section class="cex-home__hero">
            <!-- Floating Crypto Icons -->
            <div class="cex-home__hero-floating">
                <img src="/images/coins/btc.png" class="cex-home__hero-coin cex-home__hero-coin--1" alt="" />
                <img src="/images/coins/eth.png" class="cex-home__hero-coin cex-home__hero-coin--2" alt="" />
                <img src="/images/coins/usdt.png" class="cex-home__hero-coin cex-home__hero-coin--3" alt="" />
                <img src="/images/coins/bnb.png" class="cex-home__hero-coin cex-home__hero-coin--4" alt="" />
                <img src="/images/coins/sol.png" class="cex-home__hero-coin cex-home__hero-coin--5" alt="" />
            </div>
            <div class="cex-home__hero-content">
                <h1>{{ $t('The Most') }}
                    <span class="typer typer-text cex-home__typer" id="typer-home" :data-words="$t('Trusted') +','+ $t('Secure') +','+ $t('Fast') +','+ $t('Reliable')" data-delay="100" data-deleteDelay="1000"></span>
                    <span class="cursor typer-cursor" data-owner="typer-home"></span>
                    <br>{{ $t('Cryptocurrency Exchange') }}
                </h1>
                <p>{{ $t('Buy, sell, and trade cryptocurrencies on the most trusted exchange platform') }}</p>
                <div class="cex-home__hero-actions">
                    <Link :href="route('register')" class="cex-home__btn cex-home__btn--primary">
                        {{ $t('Get Started') }}
                    </Link>
                    <Link :href="route('markets')" class="cex-home__btn cex-home__btn--secondary">
                        {{ $t('View Markets') }}
                    </Link>
                </div>
                <div class="cex-home__hero-stats">
                    <div class="cex-home__hero-stat">
                        <span class="cex-home__hero-stat-value">{{ marketCountValue }}+</span>
                        <span class="cex-home__hero-stat-label">{{ $t('Trading Pairs') }}</span>
                    </div>
                    <div class="cex-home__hero-stat">
                        <span class="cex-home__hero-stat-value">24/7</span>
                        <span class="cex-home__hero-stat-label">{{ $t('Support') }}</span>
                    </div>
                    <div class="cex-home__hero-stat">
                        <span class="cex-home__hero-stat-value">20M+</span>
                        <span class="cex-home__hero-stat-label">{{ $t('Users Worldwide') }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Announcements Section -->
        <section class="cex-home__announcements" v-if="articles && articles.data && articles.data.length > 0">
            <div class="cex-home__section-header cex-home__section-header--announcements">
                <div>
                    <h2>{{ $t('Announcements') }}</h2>
                    <p>{{ $t('Latest news and updates') }}</p>
                </div>
                <Link :href="route('articles')" class="cex-home__view-all">
                    {{ $t('View All') }}
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </Link>
            </div>

            <div class="cex-home__announcements-slider">
                <hooper :settings="hooperSettings">
                    <slide v-for="article in articles.data" :key="article.id">
                        <div @click="setArticle(article)" class="cex-home__announcement-card">
                            <img :src="article.thumbnail" :alt="article.title" class="cex-home__announcement-image" />
                        </div>
                    </slide>
                </hooper>
            </div>
        </section>

        <!-- Market Ticker -->
        <section class="cex-home__ticker" v-if="sliderMarkets && sliderMarkets.length > 0">
            <marquee-slider
                id="home-marquee-slider"
                :speed="40000"
                :autoWidth="true"
                :repeat="10"
                :length="sliderMarkets.length"
            >
                <div class="cex-home__ticker-item" v-for="market in sliderMarkets" :key="market.name">
                    <img :src="market.base_currency_logo" class="cex-home__ticker-logo" />
                    <span class="cex-home__ticker-name">{{ market.name }}</span>
                    <span class="cex-home__ticker-price">{{ market.last }}</span>
                    <span class="cex-home__ticker-change" :class="{'cex-home__ticker-change--up': market.change > 0, 'cex-home__ticker-change--down': market.change < 0}">
                        {{ market.change > 0 ? '+' : '' }}{{ market.change }}%
                    </span>
                </div>
            </marquee-slider>
        </section>
        <section class="cex-home__markets-overview" v-if="sliderMarkets && sliderMarkets.length > 0">
    <div class="cex-home__section-header">
        <div>
            <h2>{{ $t('Markets Overview') }}</h2>
            <p>{{ $t('Latest market prices') }}</p>
        </div>
        <Link :href="route('markets')" class="cex-home__view-all">
            {{ $t('View All') }}
        </Link>
    </div>

    <div class="cex-home__markets-table-wrap">
        <table class="cex-home__markets-table">
            <thead>
                <tr>
                    <th>{{ $t('Pair') }}</th>
                    <th>{{ $t('Last Price') }}</th>
                    <th>{{ $t('24 Change') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="market in topMarkets"
                    :key="market.name"
                    @click="setMarket(market, false, false)"
                    class="cex-home__markets-row"
                >
                    <td>
                        <div class="cex-home__markets-pair">
                            <img :src="market.base_currency_logo" class="cex-home__markets-logo" />
                            <span>{{ market.name }}</span>
                        </div>
                    </td>
                    <td>{{ market.last }}</td>
                    <td>
                        <span
                            class="cex-home__markets-change"
                            :class="{
                                'cex-home__markets-change--up': market.change > 0,
                                'cex-home__markets-change--down': market.change < 0
                            }"
                        >
                            {{ market.change > 0 ? '+' : '' }}{{ market.change }}%
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
        <!-- Markets Section -->
        <section class="cex-home__markets">
            <div class="cex-home__section-header">
                <h2>{{ $t('Popular Markets') }}</h2>
                <Link :href="route('markets')" class="cex-home__view-all">
                    {{ $t('View All') }}
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </Link>
            </div>

            <div class="cex-home__markets-tabs">
                <button @click="setMarketTab('hot')" class="cex-home__markets-tab" :class="{'cex-home__markets-tab--active': activeMarketTab === 'hot'}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path>
                    </svg>
                    {{ $t('Hot') }}
                </button>
                <button @click="setMarketTab('gainers')" class="cex-home__markets-tab" :class="{'cex-home__markets-tab--active': activeMarketTab === 'gainers'}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                        <polyline points="17 6 23 6 23 12"></polyline>
                    </svg>
                    {{ $t('Gainers') }}
                </button>
                <button @click="setMarketTab('losers')" class="cex-home__markets-tab" :class="{'cex-home__markets-tab--active': activeMarketTab === 'losers'}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline>
                        <polyline points="17 18 23 18 23 12"></polyline>
                    </svg>
                    {{ $t('Losers') }}
                </button>
                <button @click="setMarketTab('new')" class="cex-home__markets-tab" :class="{'cex-home__markets-tab--active': activeMarketTab === 'new'}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                    </svg>
                    {{ $t('New') }}
                </button>
            </div>

            <div class="cex-home__markets-grid">
                <div v-for="market in topMarkets" :key="market.name" @click="setMarket(market, false, false)" class="cex-home__market-card">
                    <div class="cex-home__market-header">
                        <img :src="market.base_currency_logo" class="cex-home__market-logo" />
                        <div class="cex-home__market-info">
                            <span class="cex-home__market-pair">{{ market.base_currency }}<span>/{{ market.quote_currency }}</span></span>
                            <span class="cex-home__market-name">{{ market.base_currency_name }}</span>
                        </div>
                    </div>
                    <div class="cex-home__market-price">{{ market.last }}</div>
                    <div class="cex-home__market-change" :class="{'cex-home__market-change--up': market.change > 0, 'cex-home__market-change--down': market.change < 0}">
                        {{ market.change > 0 ? '+' : '' }}{{ market.change }}%
                    </div>
                    <div class="cex-home__market-volume">
                        <span class="cex-home__market-volume-label">{{ $t('Vol') }}</span>
                        {{ formatNumber(market.qVolume, 2) }}
                    </div>
                </div>
            </div>
        </section>

        <!-- Staking Section -->
        <section class="cex-home__staking" v-if="stakings && stakings.data && stakings.data.length > 0">
            <div class="cex-home__section-header">
                <div>
                    <h2>{{ $t('Staking') }}</h2>
                    <p>{{ $t('Earn rewards by staking your crypto assets') }}</p>
                </div>
                <Link :href="route('stakings')" class="cex-home__view-all">
                    {{ $t('View All') }}
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </Link>
            </div>

            <div class="cex-home__staking-grid">
                <div v-for="staking in stakings.data" :key="staking.id" @click="setStaking(staking, 0)" class="cex-home__staking-card">
                    <div class="cex-home__staking-header">
                        <img :src="staking.currency_logo" class="cex-home__staking-logo" />
                        <div class="cex-home__staking-info">
                            <span class="cex-home__staking-symbol">{{ staking.currency_symbol }}</span>
                            <span class="cex-home__staking-name">{{ staking.currency }}</span>
                        </div>
                    </div>
                    <div class="cex-home__staking-apy">
                        <span class="cex-home__staking-apy-label">{{ $t('Est. APY') }}</span>
                        <span class="cex-home__staking-apy-value">
                            <template v-if="staking.ranges">
                                {{ Object.values(staking.ranges)[Object.values(staking.ranges).length - 1] }}%
                            </template>
                        </span>
                    </div>
                    <div class="cex-home__staking-details">
                        <div class="cex-home__staking-detail">
                            <span>{{ $t('Min') }}</span>
                            <span>{{ staking.min_amount }}</span>
                        </div>
                        <div class="cex-home__staking-detail">
                            <span>{{ $t('Max') }}</span>
                            <span>{{ staking.max_amount }}</span>
                        </div>
                    </div>
                    <button class="cex-home__staking-btn">{{ $t('Stake Now') }}</button>
                </div>
            </div>
        </section>
        <!-- Quantify Section -->
        <section class="cex-home__staking" v-if="quantifies && quantifies.data && quantifies.data.length > 0">
            <div class="cex-home__section-header">
                <div>
                    <h2>{{ $t('Quantify') }}</h2>
                    <p>{{ $t('Earn rewards with automated trading strategies') }}</p>
                </div>
        
                <Link :href="route('stakings', { staking_type: 1 })" class="cex-home__view-all">
                    {{ $t('View All') }}
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </Link>
            </div>
        
            <div class="cex-home__staking-grid">
                <div
                    v-for="staking in quantifies.data"
                    :key="staking.id"
                    @click="setStaking(staking, 1)"
                    class="cex-home__staking-card"
                >
                    <div class="cex-home__staking-header">
                        <img 
                            :src="staking.currencyd && staking.currencyd.file ? staking.currencyd.file.url : staking.currency_logo" 
                            class="cex-home__staking-logo" 
                        />
                        <div class="cex-home__staking-info">
                            <span class="cex-home__staking-symbol">
                                {{ staking.currency_symbol }}
                                <template v-if="staking_type === 1"> - USDT</template>
                            </span>
                            <span class="cex-home__staking-name">
                                {{ staking_type === 1 && staking.currencyd ? staking.currencyd.name : staking.currency }}
                            </span>
                        </div>
                    </div>
        
                    <div class="cex-home__staking-apy">
                        <span class="cex-home__staking-apy-label">{{ $t('Est. APY') }}</span>
                        <span class="cex-home__staking-apy-value">
                            <template v-if="staking.ranges">
                                {{ Object.values(staking.ranges)[Object.values(staking.ranges).length - 1] }}% - {{ staking.rewards_percentage_a }}%
                            </template>
                        </span>
                    </div>
        
                    <div class="cex-home__staking-details">
                        <div class="cex-home__staking-detail">
                            <span>{{ $t('Min') }}</span>
                            <span>{{ staking.min_amount }}</span>
                        </div>
                        <div class="cex-home__staking-detail">
                            <span>{{ $t('Max') }}</span>
                            <span>{{ staking.max_amount }}</span>
                        </div>
                    </div>
        
                    <button class="cex-home__staking-btn">
                        {{ $t('Start Quantify') }}
                    </button>
                </div>
            </div>
        </section>
        <!-- Launchpad Section -->
        <section class="cex-home__launchpad" v-if="launchpads && launchpads.data && launchpads.data.length > 0">
            <div class="cex-home__section-header">
                <div>
                    <h2>{{ $t('Launchpad') }}</h2>
                    <p>{{ $t('Discover and participate in token launches') }}</p>
                </div>
                <Link :href="route('launchpads')" class="cex-home__view-all">
                    {{ $t('View All') }}
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </Link>
            </div>

            <div class="cex-home__launchpad-grid">
                <div v-for="launchpad in launchpads.data" :key="launchpad.id" @click="setLaunchpad(launchpad)" class="cex-home__launchpad-card">
                    <div class="cex-home__launchpad-header">
                        <img :src="launchpad.currency_logo" class="cex-home__launchpad-logo" />
                        <div class="cex-home__launchpad-info">
                            <span class="cex-home__launchpad-symbol">{{ launchpad.currency_symbol }}</span>
                            <span class="cex-home__launchpad-name">{{ launchpad.name }}</span>
                        </div>
                        <div class="cex-home__launchpad-status" :class="{
                            'cex-home__launchpad-status--active': launchpad.status === 'open',
                            'cex-home__launchpad-status--upcoming': launchpad.status === 'pending'
                        }">
                            {{ launchpad.status === 'open' ? $t('Active') : $t('Upcoming') }}
                        </div>
                    </div>
                    <div class="cex-home__launchpad-progress">
                        <div class="cex-home__launchpad-progress-header">
                            <span>{{ $t('Progress') }}</span>
                            <span>{{ launchpad.filled }}%</span>
                        </div>
                        <div class="cex-home__launchpad-progress-bar">
                            <div class="cex-home__launchpad-progress-fill" :style="{ width: launchpad.filled + '%' }"></div>
                        </div>
                    </div>
                    <div class="cex-home__launchpad-details">
                        <div class="cex-home__launchpad-detail">
                            <span>{{ $t('Hard Cap') }}</span>
                            <span>{{ launchpad.hard_cap }}</span>
                        </div>
                        <div class="cex-home__launchpad-detail">
                            <span>{{ $t('Price') }}</span>
                            <span>{{ launchpad.rate }}</span>
                        </div>
                    </div>
                    <button class="cex-home__launchpad-btn" v-if="launchpad.status === 'open'">{{ $t('Participate') }}</button>
                    <button class="cex-home__launchpad-btn cex-home__launchpad-btn--upcoming" v-else>{{ $t('Coming Soon') }}</button>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section class="cex-home__features">
            <div class="cex-home__section-header cex-home__section-header--center">
                <h2>{{ $t('Why Choose Us') }}</h2>
                <p>{{ $t('Industry-leading security and innovative features') }}</p>
            </div>

            <div class="cex-home__features-grid">
                <div class="cex-home__feature-card">
                    <div class="cex-home__feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        </svg>
                    </div>
                    <h3>{{ $t('Secure Storage') }}</h3>
                    <p>{{ $t('95% of assets stored in cold wallets with multi-signature protection') }}</p>
                </div>
                <div class="cex-home__feature-card">
                    <div class="cex-home__feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <h3>{{ $t('24/7 Support') }}</h3>
                    <p>{{ $t('Round-the-clock customer support to assist you anytime') }}</p>
                </div>
                <div class="cex-home__feature-card">
                    <div class="cex-home__feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="1" x2="12" y2="23"></line>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                        </svg>
                    </div>
                    <h3>{{ $t('Low Fees') }}</h3>
                    <p>{{ $t('Competitive trading fees starting from just 0.1%') }}</p>
                </div>
                <div class="cex-home__feature-card">
                    <div class="cex-home__feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                            <line x1="8" y1="21" x2="16" y2="21"></line>
                            <line x1="12" y1="17" x2="12" y2="21"></line>
                        </svg>
                    </div>
                    <h3>{{ $t('Advanced Trading') }}</h3>
                    <p>{{ $t('Professional tools for spot, futures, and options trading') }}</p>
                </div>
            </div>
        </section>
        <section class="cex-home__policy">
    <div class="cex-home__section-header cex-home__section-header--center cex-home__policy-header">
        <h2>{{ $t('Why Credit Card Purchases Are Not Supported') }}</h2>
        <p>{{ $t('Built around compliance, security, and a crypto-native operating model') }}</p>
        <div class="cex-home__policy-divider"></div>
    </div>

    <div class="cex-home__policy-grid">
        <!-- 1 -->
        <article class="cex-home__policy-card">
            <div class="cex-home__policy-card-media">
                <img
                    src="/storage/uploads/policy-banking-restrictions.png"
                    alt="Regulatory and Banking Restrictions"
                    class="cex-home__policy-card-image"
                />
            </div>

            <div class="cex-home__policy-card-body">
                <div class="cex-home__policy-card-title-wrap">
                    <div class="cex-home__policy-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 10h18"></path>
                            <path d="M7 10V6a5 5 0 0 1 10 0v4"></path>
                            <rect x="3" y="10" width="18" height="11" rx="2"></rect>
                        </svg>
                    </div>
                    <div>
                        <h3>{{ $t('Regulatory and Banking Restrictions') }}</h3>
                        <p class="cex-home__policy-card-subtitle">
                            {{ $t('External compliance and banking realities shape payment availability') }}
                        </p>
                    </div>
                </div>

                <ul class="cex-home__policy-list">
                    <li>
                        <strong>{{ $t('Banking Industry De-Risking') }}：</strong>
                        {{ $t('Many banks, especially in the United States, the European Union, and Taiwan, take a conservative approach toward crypto-related merchants and may proactively close accounts or refuse cooperation. Even legally operating platforms may struggle to secure banking partners willing to support card acquiring services.') }}
                    </li>
                    <li>
                        <strong>{{ $t('Jurisdictional Regulatory Limits') }}：</strong>
                        {{ $t('Some jurisdictions allow cryptocurrency trading while restricting or prohibiting the use of credit cards to purchase virtual assets. Platforms operating in such regions may need to avoid direct card channels entirely.') }}
                    </li>
                    <li>
                        <strong>{{ $t('Card Network Classification Rules') }}：</strong>
                        {{ $t('Visa and Mastercard generally assign crypto merchants specific MCC classifications such as 6051, which can trigger enhanced compliance reviews, reserve requirements, and higher processing fees. These thresholds are difficult for many small and mid-sized platforms to meet.') }}
                    </li>
                </ul>
            </div>
        </article>

        <!-- 2 -->
        <article class="cex-home__policy-card">
            <div class="cex-home__policy-card-media">
                <img
                    src="/storage/uploads/policy-business-risk.png"
                    alt="Business and Risk Considerations"
                    class="cex-home__policy-card-image"
                />
            </div>

            <div class="cex-home__policy-card-body">
                <div class="cex-home__policy-card-title-wrap">
                    <div class="cex-home__policy-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    </div>
                    <div>
                        <h3>{{ $t('Business and Risk Considerations') }}</h3>
                        <p class="cex-home__policy-card-subtitle">
                            {{ $t('Card rails can introduce asymmetric risk to exchanges') }}
                        </p>
                    </div>
                </div>

                <ul class="cex-home__policy-list">
                    <li>
                        <strong>{{ $t('Chargeback Exposure') }}：</strong>
                        {{ $t('This is often the most critical issue. Crypto transfers are irreversible, while cardholders may dispute transactions for an extended period. Fraudsters can use stolen cards to buy digital assets and later initiate chargebacks, leaving the platform exposed to losses in both fiat and crypto.') }}
                    </li>
                    <li>
                        <strong>{{ $t('High Processing Fees') }}：</strong>
                        {{ $t('Card acquiring fees commonly range from 2% to 4%, and may be even higher for crypto-related merchants. By comparison, stablecoin transfers and wire deposits are often significantly more cost-efficient, allowing platforms to maintain more competitive overall fee structures.') }}
                    </li>
                    <li>
                        <strong>{{ $t('Fraud and AML Pressure') }}：</strong>
                        {{ $t('Crypto exchanges are attractive targets for organized fraud networks. Disabling card purchase channels helps cut off one of the most common routes for stolen card activity and reduces AML compliance burdens as well as enforcement risk.') }}
                    </li>
                </ul>
            </div>
        </article>

        <!-- 3 -->
        <article class="cex-home__policy-card">
            <div class="cex-home__policy-card-media">
                <img
                    src="/storage/uploads/policy-crypto-native.png"
                    alt="Strategic Business Choices"
                    class="cex-home__policy-card-image"
                />
            </div>

            <div class="cex-home__policy-card-body">
                <div class="cex-home__policy-card-title-wrap">
                    <div class="cex-home__policy-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M12 2v3"></path>
                            <path d="M12 19v3"></path>
                            <path d="M4.93 4.93l2.12 2.12"></path>
                            <path d="M16.95 16.95l2.12 2.12"></path>
                            <path d="M2 12h3"></path>
                            <path d="M19 12h3"></path>
                            <path d="M4.93 19.07l2.12-2.12"></path>
                            <path d="M16.95 7.05l2.12-2.12"></path>
                        </svg>
                    </div>
                    <div>
                        <h3>{{ $t('Strategic Business Choices') }}</h3>
                        <p class="cex-home__policy-card-subtitle">
                            {{ $t('Some exchanges intentionally choose a crypto-native model') }}
                        </p>
                    </div>
                </div>

                <ul class="cex-home__policy-list">
                    <li>
                        <strong>{{ $t('Crypto-Native Operating Model') }}：</strong>
                        {{ $t('Certain platforms deliberately avoid fiat-heavy infrastructure and focus on crypto-to-crypto services. This simplifies system design, lowers regulatory overhead, and clearly signals a crypto-native brand position to on-chain users.') }}
                    </li>
                    <li>
                        <strong>{{ $t('Stablecoin-Centered Access') }}：</strong>
                        {{ $t('Using stablecoins such as USDT and USDC as primary deposit channels allows platforms to serve users who already hold digital assets, while reducing the friction and cost associated with fiat conversion. This approach is especially common across Taiwan and Southeast Asia.') }}
                    </li>
                    <li>
                        <strong>{{ $t('Focus on Professional and Institutional Clients') }}：</strong>
                        {{ $t('If a platform primarily serves professional traders, institutions, or business clients, these users are more likely to rely on bank wires or OTC settlement rather than consumer credit cards. Resources can therefore be concentrated on high-value compliance and service workflows.') }}
                    </li>
                </ul>
            </div>
        </article>

        <!-- 4 -->
        <article class="cex-home__policy-card">
            <div class="cex-home__policy-card-media">
                <img
                    src="/storage/uploads/policy-technical-operations.png"
                    alt="Technical and Operational Factors"
                    class="cex-home__policy-card-image"
                />
            </div>

            <div class="cex-home__policy-card-body">
                <div class="cex-home__policy-card-title-wrap">
                    <div class="cex-home__policy-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82L4.21 7.2a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c0 .66.39 1.26 1 1.51.16.06.33.09.51.09H21a2 2 0 1 1 0 4h-.09c-.18 0-.35.03-.51.09-.61.25-1 .85-1 1.51z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3>{{ $t('Technical and Operational Factors') }}</h3>
                        <p class="cex-home__policy-card-subtitle">
                            {{ $t('Card support is not just a payment switch, it is an entire operational stack') }}
                        </p>
                    </div>
                </div>

                <ul class="cex-home__policy-list">
                    <li>
                        <strong>{{ $t('Complexity of Fiat Integration') }}：</strong>
                        {{ $t('Supporting card acquiring requires PCI DSS compliance, key management, fraud prevention systems, chargeback handling, and reconciliation workflows. For early-stage or lean teams, this can create heavy technical and operational overhead.') }}
                    </li>
                    <li>
                        <strong>{{ $t('Third-Party Fiat Gateways') }}：</strong>
                        {{ $t('Many platforms prefer to work with third-party fiat gateway providers such as MoonPay, Ramp, or Banxa instead of building in-house card processing. If partnerships are not yet finalized, temporarily withholding card purchase options is often the more cautious approach.') }}
                    </li>
                    <li>
                        <strong>{{ $t('Phased Product Rollout') }}：</strong>
                        {{ $t('For many exchanges, it is more practical to first deliver a strong crypto-native user experience and only later expand into additional fiat channels. This phased approach supports better control over risk, compliance, and infrastructure maturity.') }}
                    </li>
                </ul>
            </div>
        </article>
    </div>
</section>
        <!-- CTA Section -->
        <section class="cex-home__cta">
            <div class="cex-home__cta-content">
                <template v-if="$page.props.user">
                    <h2>{{ $t('Ready to Trade?') }}</h2>
                    <p>{{ $t('Access all markets and start trading now') }}</p>
                    <Link :href="route('markets')" class="cex-home__btn cex-home__btn--primary cex-home__btn--large">
                        {{ $t('Go to Markets') }}
                    </Link>
                </template>
                <template v-else>
                    <h2>{{ $t('Start Trading Today') }}</h2>
                    <p>{{ $t('Join millions of traders and investors on the most trusted crypto exchange') }}</p>
                    <Link :href="route('register')" class="cex-home__btn cex-home__btn--primary cex-home__btn--large">
                        {{ $t('Create Free Account') }}
                    </Link>
                </template>
            </div>
        </section>
    </div>

    <market-channel></market-channel>
</app-layout>
