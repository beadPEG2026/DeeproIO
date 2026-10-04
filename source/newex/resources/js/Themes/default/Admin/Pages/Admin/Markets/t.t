 <div class="pr-6 pb-8 w-full lg:w-1/2">
                        <label class="form-label d-block">趋势强度（0.0 - 1.0）：</label>
                        <input type="range" v-model="form.bot_trend_strength" min="0" max="1" step="0.05" class="w-full" />
                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                            <span>弱（0）</span>
                            <span class="font-medium">{{ form.bot_trend_strength }}</span>
                            <span>强（1）</span>
                        </div>
                    </div>

                    <!-- Volatility Settings -->
                    <div class="pr-6 pb-8 w-full">
                        <div class="font-weight-bold text-lg border-b mt-2 mb-2 pb-2 font-medium text-gray-600">波动性设置</div>
                        <p class="text-sm text-gray-500">控制每个周期之间价格波动的幅度。</p>
                    </div>

                    <text-input v-model="form.bot_volatility" :error="errors.bot_volatility" class="pr-6 pb-8 w-full lg:w-1/2" label="基础波动率（%）" placeholder="例如：0.02 表示 2%" />

                    <text-input v-model="form.bot_volatility_burst_chance" :error="errors.bot_volatility_burst_chance" class="pr-6 pb-8 w-full lg:w-1/2" label="波动爆发概率（%）" placeholder="例如：0.05 表示 5%" />

                    <!-- Order Book Settings -->
                    <div class="pr-6 pb-8 w-full">
                        <div class="font-weight-bold text-lg border-b mt-2 mb-2 pb-2 font-medium text-gray-600">订单簿设置</div>
                        <p class="text-sm text-gray-500">配置订单簿深度和价差。</p>
                    </div>

                    <text-input v-model="form.bot_orderbook_depth" :error="errors.bot_orderbook_depth" class="pr-6 pb-8 w-full lg:w-1/2" label="订单簿深度（每边）" placeholder="例如：20" />

                    <text-input v-model="form.bot_spread_percentage" :error="errors.bot_spread_percentage" class="pr-6 pb-8 w-full lg:w-1/2" label="买卖价差（%）" placeholder="例如：0.001 表示 0.1%" />

                    <!-- Trade Volume Settings -->
                    <div class="pr-6 pb-8 w-full">
                        <div class="font-weight-bold text-lg border-b mt-2 mb-2 pb-2 font-medium text-gray-600">交易量设置</div>
                        <p class="text-sm text-gray-500">配置交易数量和频率。</p>
                    </div>

                    <text-input v-model="form.custom_liquidity_start_amount" :error="errors.custom_liquidity_start_amount" class="pr-6 pb-8 w-full lg:w-1/2" label="最小交易数量" />

                    <text-input v-model="form.custom_liquidity_end_amount" :error="errors.custom_liquidity_end_amount" class="pr-6 pb-8 w-full lg:w-1/2" label="最大交易数量" />

                    <div class="pr-6 pb-8 w-full lg:w-1/2">
                        <label class="form-label d-block">交易频率（0-100%）：</label>
                        <input type="range" v-model="form.bot_trade_frequency" min="0" max="100" step="5" class="w-full" />
                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                            <span>稀少（0%）</span>
                            <span class="font-medium">{{ form.bot_trade_frequency }}%</span>
                            <span>频繁（100%）</span>
                        </div>
                    </div>

                    <!-- Cycle Interval Settings -->
                    <div class="pr-6 pb-8 w-full">
                        <div class="font-weight-bold text-lg border-b mt-2 mb-2 pb-2 font-medium text-gray-600">更新时间设置</div>
                        <p class="text-sm text-gray-500">以毫秒为单位设置灵活的循环间隔。机器人会在最小值和最大值之间随机选择延迟，以获得更自然、不可预测的节奏。</p>
                    </div>

                    <text-input v-model="form.bot_cycle_interval_min" :error="errors.bot_cycle_interval_min" class="pr-6 pb-8 w-full lg:w-1/2" label="最小循环间隔（毫秒）" placeholder="例如：500 表示 0.5 秒" />

                    <text-input v-model="form.bot_cycle_interval_max" :error="errors.bot_cycle_interval_max" class="pr-6 pb-8 w-full lg:w-1/2" label="最大循环间隔（毫秒）" placeholder="例如：3000 表示 3 秒" />

                    <div class="pr-6 pb-8 w-full">
                        <p class="text-xs text-gray-400">示例：500ms = 0.5秒，1000ms = 1秒，2500ms = 2.5秒，5000ms = 5秒</p>
                    </div>