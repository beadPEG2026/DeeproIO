<?php
return json_decode(<<<'JSON'
[
  {
    "question": {
      "en": "How do I create an account?",
      "zh-cn": "如何建立账户？",
      "zh-tw": "如何建立帳戶？"
    },
    "answer": {
      "en": "Open Register, enter your email, complete the verification steps shown on the page and set your password.",
      "zh-cn": "进入注册页，输入电子邮件，完成页面要求的验证并设置密码。",
      "zh-tw": "進入註冊頁，輸入電子郵件，完成頁面要求的驗證並設定密碼。"
    },
    "category": "account",
    "visible": true
  },
  {
    "question": {
      "en": "What if I cannot sign in?",
      "zh-cn": "无法登录怎么办？",
      "zh-tw": "無法登入怎麼辦？"
    },
    "answer": {
      "en": "Check your account details and use Forgot password to recover access. Contact support if you still need help.",
      "zh-cn": "请先核对账户信息，也可通过「忘记密码」找回登录权限。如仍有问题，请联系支持中心。",
      "zh-tw": "請先核對帳戶資訊，也可透過「忘記密碼」找回登入權限。如仍有問題，請聯絡支援中心。"
    },
    "category": "account",
    "visible": true
  },
  {
    "question": {
      "en": "Where can I find my assets?",
      "zh-cn": "在哪里查看资产？",
      "zh-tw": "在哪裡查看資產？"
    },
    "answer": {
      "en": "Open Assets to view your funding and trading accounts, available balances and transaction records.",
      "zh-cn": "进入「资产」，查看资金及交易账户、可用余额和资金记录。",
      "zh-tw": "進入「資產」，查看資金及交易帳戶、可用餘額和資金記錄。"
    },
    "category": "assets",
    "visible": true
  },
  {
    "question": {
      "en": "How do I deposit or withdraw?",
      "zh-cn": "如何充值或提现？",
      "zh-tw": "如何充值或提現？"
    },
    "answer": {
      "en": "Select the asset and supported network in Assets. Check the complete address, minimum amount and fees shown before confirming.",
      "zh-cn": "在资产页选择币种及支持的网络，确认完整地址、最低金额和手续费后再操作。",
      "zh-tw": "在資產頁選擇幣種及支援的網路，確認完整地址、最低金額和手續費後再操作。"
    },
    "category": "assets",
    "visible": true
  },
  {
    "question": {
      "en": "How do I transfer between accounts?",
      "zh-cn": "如何在账户间划转？",
      "zh-tw": "如何在帳戶間劃轉？"
    },
    "answer": {
      "en": "Use Transfer to choose the source account, destination account, asset and amount. Only the available balance can be transferred.",
      "zh-cn": "使用划转功能，选择转出账户、转入账户、币种和金额。仅可划转可用余额。",
      "zh-tw": "使用劃轉功能，選擇轉出帳戶、轉入帳戶、幣種和金額。僅可劃轉可用餘額。"
    },
    "category": "assets",
    "visible": true
  },
  {
    "question": {
      "en": "Where are UMI services?",
      "zh-cn": "UMI 服务在哪里？",
      "zh-tw": "UMI 服務在哪裡？"
    },
    "answer": {
      "en": "Open UMI Ecosystem from the navigation to view ecosystem services and your legacy account verification options.",
      "zh-cn": "从导览列进入 UMI 生态，查看生态服务与原账户核验入口。",
      "zh-tw": "從導覽列進入 UMI 生態，查看生態服務與原帳戶核驗入口。"
    },
    "category": "umi",
    "visible": true
  },
  {
    "question": {
      "en": "Where can I find stock tokens?",
      "zh-cn": "在哪里查看币股？",
      "zh-tw": "在哪裡查看幣股？"
    },
    "answer": {
      "en": "Open Stock Tokens to view supported tokens, their issuer, network, contract address and market data.",
      "zh-cn": "进入币股板块，查看已接入代币的发行方、网络、合约地址与行情。",
      "zh-tw": "進入幣股板塊，查看已接入代幣的發行方、網路、合約地址與行情。"
    },
    "category": "trading",
    "visible": true
  },
  {
    "question": {
      "en": "Why does an order remain open?",
      "zh-cn": "为什么委托尚未成交？",
      "zh-tw": "為什麼委託尚未成交？"
    },
    "answer": {
      "en": "An order needs matching liquidity and an eligible price to fill. View its status in Open orders and review the market order book.",
      "zh-cn": "委托需要符合价格条件及相应对手方才能成交。请在当前委托中查看状态，并核对市场深度。",
      "zh-tw": "委託需要符合價格條件及相應對手方才能成交。請在當前委託中查看狀態，並核對市場深度。"
    },
    "category": "trading",
    "visible": true
  }
]
JSON, true, 512, JSON_THROW_ON_ERROR);
