export function authError(message, locale = 'en') {
    if (!String(locale).startsWith('zh') || !message) return message;
    const traditional = String(locale) === 'zh-tw';
    if (/(?:password|密码|密碼).*at least (\d+) characters/i.test(message)) {
        const count = message.match(/at least (\d+) characters/i)[1];
        return traditional ? `密碼至少需要 ${count} 個字元。` : `密码至少需要 ${count} 个字符。`;
    }
    if (/(?:password|密码|密碼).*(confirmation|confirmed|match|确认|確認)/i.test(message)) return traditional ? '兩次輸入的密碼不一致。' : '两次输入的密码不一致。';
    if (/(?:password|密码|密碼).*required/i.test(message)) return traditional ? '請輸入密碼。' : '请输入密码。';
    if (/(?:email|邮箱|郵箱|电子邮件|電子郵件).*required/i.test(message)) return traditional ? '請輸入電子郵件。' : '请输入邮箱。';
    if (/(?:email|邮箱|郵箱|电子邮件|電子郵件).*(valid|invalid)/i.test(message)) return traditional ? '請輸入有效的電子郵件地址。' : '请输入有效的邮箱地址。';
    return message;
}
