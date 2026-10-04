const messages={
 'Enter details':['填写信息','填寫資料'],
 'Hide balance':['隐藏资产余额','隱藏資產餘額'],
 'Show balance':['显示资产余额','顯示資產餘額'],
 'This feature is not available yet':['暂未开通此功能','暫未開通此功能'],
 'Funds are held by the Deepro protection pool. Safe and trusted.':['资金由 Deepro 保障池托管，安全可信','資金由 Deepro 保障池託管，安全可信'],
 'Scan QR code':['扫一扫','掃一掃'],
 'Choose QR image':['从相册选择','從相簿選擇'],
 'Point your camera at the wallet QR code.':['将钱包二维码放入框内，即可自动识别','將錢包二維碼放入框內，即可自動識別'],
 'Opening camera…':['正在打开相机…','正在開啟相機…'],
 'Allow camera access to scan, or choose a QR image.':['请允许使用相机后扫码，也可以从相册选择二维码','請允許使用相機後掃碼，也可以從相簿選擇二維碼'],
 'Camera is unavailable. Choose a QR image or paste the address.':['当前无法使用相机，请从相册选择二维码或粘贴地址','目前無法使用相機，請從相簿選擇二維碼或貼上地址'],
 'Camera is in use. Close other camera apps and retry.':['相机正在被使用，请关闭其他相机应用后重试','相機正在使用中，請關閉其他相機應用程式後重試'],
 'Try camera again':['重新打开相机','重新開啟相機'],
 'Close scanner':['关闭扫码','關閉掃碼'],
 'Please select network':['请选择网络','請選擇網路'],
 'You can enter an address first. Select a network to validate it.':['可先填写地址，选择网络后校验。','可先填寫地址，選擇網路後驗證。'],
 'Select a network before scanning the recipient address.':['请先选择网络，再扫描收款地址。','請先選擇網路，再掃描收款地址。'],
 'Internal receiving code':['内部收款码','內部收款碼'],
 'Enter the recipient’s internal receiving code':['请输入收款人的内部收款码','請輸入收款人的內部收款碼'],
 'Ask the recipient to copy their internal receiving code from Profile → Internal receiving code. This is not their UID or UMI invitation code.':['请收款人在“个人中心 → 内部收款码”复制提供，不是 UID 或 UMI 邀请码。','請收款人在「個人中心 → 內部收款碼」複製提供，不是 UID 或 UMI 邀請碼。'],
 'Invalid wallet address':['请输入有效的收款地址','請輸入有效的收款地址'],
 'QR code does not match the selected network.':['二维码地址与所选网络不匹配，请检查后重试。','二維碼地址與所選網路不符，請檢查後重試。'],
 'Choose a QR image smaller than 10 MB.':['请选择小于 10 MB 的二维码图片。','請選擇小於 10 MB 的二維碼圖片。'],
 'Unable to read this image.':['无法读取此图片，请重新选择。','無法讀取此圖片，請重新選擇。'],
 'No QR code found. Try a clearer image.':['未识别到二维码，请选择更清晰的图片。','未識別到二維碼，請選擇更清晰的圖片。'],
 'Unable to import address. Please paste it manually.':['无法导入地址，请手动粘贴收款地址。','無法匯入地址，請手動貼上收款地址。'],
 'Clipboard access is unavailable. Paste the address manually.':['无法读取剪贴板，请手动粘贴收款地址。','無法讀取剪貼簿，請手動貼上收款地址。'],
 'Unable to save address. Please retry.':['地址保存失败，请重试。','地址儲存失敗，請重試。'],
};
export function walletUiCopy(vm,key) {
 const locale=String(vm.$i18n?.locale || vm.$page?.props?.locale || '').toLowerCase();
 if (locale.startsWith('zh') && messages[key]) return messages[key][/tw|hk|hant/.test(locale)?1:0];
 return vm.$t(key);
}
