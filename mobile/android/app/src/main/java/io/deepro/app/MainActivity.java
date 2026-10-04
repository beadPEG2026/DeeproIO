package io.deepro.app;

import android.app.*;
import android.content.*;
import android.graphics.Color;
import android.net.Uri;
import android.net.http.SslError;
import android.os.*;
import android.view.*;
import android.webkit.*;
import android.widget.*;
import java.util.Locale;
import java.util.Set;
import java.util.HashSet;
import java.util.Arrays;

/** Thin HTTPS client. Authentication and transaction authorization stay on the server. */
public final class MainActivity extends Activity {
    private static final String HOME = "https://deepro.io/";
    private static final int FILE_PICKER = 31;
    private static final Set<String> EXTERNAL_SCHEMES = new HashSet<>(Arrays.asList("https", "mailto", "tel", "wc", "metamask", "trust", "tronlink", "phantom"));
    private WebView web;
    private ProgressBar progress;
    private LinearLayout error;
    private ValueCallback<Uri[]> fileCallback;
    private String lastPage = HOME;

    static boolean internal(Uri uri) {
        String host = uri.getHost();
        return "https".equalsIgnoreCase(uri.getScheme()) && uri.getUserInfo() == null
            && (uri.getPort() == -1 || uri.getPort() == 443)
            && ("deepro.io".equalsIgnoreCase(host) || "www.deepro.io".equalsIgnoreCase(host));
    }
    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        FrameLayout root = new FrameLayout(this); root.setBackgroundColor(Color.WHITE);
        if (Build.VERSION.SDK_INT >= 30) {
            getWindow().setDecorFitsSystemWindows(false);
            root.setOnApplyWindowInsetsListener((v, insets) -> {
                android.graphics.Insets a = insets.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout() | WindowInsets.Type.ime());
                v.setPadding(a.left, a.top, a.right, a.bottom); return WindowInsets.CONSUMED;
            });
        }
        web = new WebView(this); root.addView(web, new FrameLayout.LayoutParams(-1, -1));
        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        root.addView(progress, new FrameLayout.LayoutParams(-1, 5));
        error = new LinearLayout(this); error.setGravity(Gravity.CENTER); error.setOrientation(LinearLayout.VERTICAL);
        error.setPadding(36, 40, 36, 40); error.setBackgroundColor(Color.WHITE); error.setVisibility(View.GONE);
        TextView title = new TextView(this); title.setText("暂时无法连接 Deepro"); title.setTextSize(21); title.setTextColor(Color.rgb(32,38,48)); error.addView(title);
        TextView note = new TextView(this); note.setText("请检查网络后重试。交易操作不会自动重发。"); note.setPadding(0,24,0,30); error.addView(note);
        Button retry = new Button(this); retry.setText("重新连接"); retry.setOnClickListener(v -> { error.setVisibility(View.GONE); web.loadUrl(lastPage); }); error.addView(retry);
        Button browser = new Button(this); browser.setText("在浏览器中打开官网"); browser.setOnClickListener(v -> openExternal(Uri.parse(HOME))); error.addView(browser);
        root.addView(error, new FrameLayout.LayoutParams(-1,-1)); setContentView(root);
        WebSettings s = web.getSettings(); s.setJavaScriptEnabled(true); s.setDomStorageEnabled(true);
        s.setUseWideViewPort(true); s.setLoadWithOverviewMode(true);
        s.setAllowFileAccess(false); s.setAllowContentAccess(false); s.setAllowFileAccessFromFileURLs(false); s.setAllowUniversalAccessFromFileURLs(false);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW); s.setSafeBrowsingEnabled(true);
        s.setGeolocationEnabled(false); s.setMediaPlaybackRequiresUserGesture(true); s.setSupportMultipleWindows(false);
        s.setUserAgentString(s.getUserAgentString()+" DeeproAndroid/1.0.1");
        CookieManager.getInstance().setAcceptCookie(true); CookieManager.getInstance().setAcceptThirdPartyCookies(web,false);
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);
        web.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (!request.isForMainFrame()) return false;
                Uri uri = request.getUrl();
                if (internal(uri)) return false;
                if (request.hasGesture()) confirmExternal(uri);
                return true;
            }
            @Override public void onPageStarted(WebView view,String url,android.graphics.Bitmap icon) {
                if (!internal(Uri.parse(url))) { view.stopLoading(); showError(); return; }
                lastPage = url; progress.setVisibility(View.VISIBLE);
            }
            @Override public void onPageFinished(WebView view,String url) { progress.setVisibility(View.GONE); CookieManager.getInstance().flush(); }
            @Override public void onReceivedError(WebView view,WebResourceRequest request,WebResourceError e) { if(request.isForMainFrame()) showError(); }
            @Override public void onReceivedHttpError(WebView view,WebResourceRequest request,WebResourceResponse response) { if(request.isForMainFrame() && response.getStatusCode()>=500) showError(); }
            @Override public void onReceivedSslError(WebView view,SslErrorHandler handler,SslError e) { handler.cancel(); showError(); }
            @Override public void onFormResubmission(WebView view,Message dontResend,Message resend) { dontResend.sendToTarget(); }
            @Override public void onReceivedHttpAuthRequest(WebView view,HttpAuthHandler handler,String host,String realm) { handler.cancel(); }
        });
        web.setWebChromeClient(new WebChromeClient() {
            @Override public void onProgressChanged(WebView view,int value) { progress.setProgress(value); }
            @Override public void onPermissionRequest(PermissionRequest request) { request.deny(); }
            @Override public boolean onShowFileChooser(WebView view,ValueCallback<Uri[]> callback,FileChooserParams params) {
                if (!internal(Uri.parse(view.getUrl()==null?HOME:view.getUrl()))) return false;
                if(fileCallback!=null) fileCallback.onReceiveValue(null); fileCallback=callback;
                Intent pick=new Intent(Intent.ACTION_OPEN_DOCUMENT); pick.addCategory(Intent.CATEGORY_OPENABLE); pick.setType("*/*");
                pick.putExtra(Intent.EXTRA_MIME_TYPES,new String[]{"image/*","application/pdf"});
                pick.putExtra(Intent.EXTRA_ALLOW_MULTIPLE,params.getMode()==FileChooserParams.MODE_OPEN_MULTIPLE);
                try { startActivityForResult(pick,FILE_PICKER); } catch(ActivityNotFoundException e) { fileCallback.onReceiveValue(null); fileCallback=null; }
                return true;
            }
        });
        web.setDownloadListener((url,ua,disposition,mime,length) -> { Uri uri=Uri.parse(url); if(internal(uri)) confirmExternal(uri); });
        if(state==null || web.restoreState(state)==null) web.loadUrl(HOME);
    }
    private void showError() { progress.setVisibility(View.GONE); error.setVisibility(View.VISIBLE); }
    private void confirmExternal(Uri uri) {
        String scheme=uri.getScheme()==null?"":uri.getScheme().toLowerCase(Locale.ROOT);
        if(!EXTERNAL_SCHEMES.contains(scheme) || uri.getUserInfo()!=null) return;
        new AlertDialog.Builder(this).setTitle("打开外部应用").setMessage("即将离开 Deepro，继续打开此链接？")
            .setNegativeButton("取消",null).setPositiveButton("继续",(d,w)->openExternal(uri)).show();
    }
    private void openExternal(Uri uri) {
        try { startActivity(new Intent(Intent.ACTION_VIEW,uri).addCategory(Intent.CATEGORY_BROWSABLE)); }
        catch(ActivityNotFoundException e) { Toast.makeText(this,"未找到可打开此链接的应用",Toast.LENGTH_LONG).show(); }
    }
    private boolean validFile(Uri uri) {
        if(uri==null || !"content".equals(uri.getScheme())) return false;
        String type=getContentResolver().getType(uri);
        if(type==null || !(type.startsWith("image/") || type.equals("application/pdf"))) return false;
        try(ParcelFileDescriptor file=getContentResolver().openFileDescriptor(uri,"r")) {
            return file!=null && file.getStatSize()>=0 && file.getStatSize()<=20*1024*1024;
        } catch(Exception e) { return false; }
    }
    @Override protected void onActivityResult(int request,int result,Intent data) {
        super.onActivityResult(request,result,data);
        if(request==FILE_PICKER && fileCallback!=null) {
            Uri[] files=WebChromeClient.FileChooserParams.parseResult(result,data);
            if(files!=null) for(Uri file:files) if(!validFile(file)) { files=null; Toast.makeText(this,"请选择不超过 20 MB 的图片或 PDF",Toast.LENGTH_LONG).show(); break; }
            fileCallback.onReceiveValue(files);fileCallback=null;
        }
    }
    @Override public void onBackPressed() { if(error.getVisibility()==View.VISIBLE){error.setVisibility(View.GONE);web.loadUrl(HOME);} else if(web.canGoBack())web.goBack();else super.onBackPressed(); }
    @Override protected void onSaveInstanceState(Bundle out){super.onSaveInstanceState(out);web.saveState(out);}
    @Override protected void onPause(){CookieManager.getInstance().flush();web.onPause();super.onPause();}
    @Override protected void onResume(){super.onResume();if(web!=null)web.onResume();}
    @Override protected void onDestroy(){if(fileCallback!=null)fileCallback.onReceiveValue(null);web.destroy();super.onDestroy();}
}
