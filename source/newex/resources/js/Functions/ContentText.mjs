export function contentText(value,locale='en') {
    if(typeof value==='string')return value;
    return value?.[locale] || value?.[locale.startsWith('zh')?'zh-cn':'en'] || value?.en || '';
}
