# Deepro mobile clients

Android uses a constrained WebView and iOS uses WKWebView. Login and transaction authorization remain server-side.

## Android

Use JDK 17+, Gradle 8.12, Android Gradle Plugin 8.9.1 and Android SDK 35. Configure `ANDROID_HOME` or an ignored `android/local.properties` file, then run `gradle assembleRelease` from `android`.

The release must be signed with the owner's key using Android build tools. Keep the keystore and passwords outside the repository. Updates to an existing installation require its original signing identity. Increment versionCode/versionName and update the download manifest after building and signing. Release WebView debugging is disabled.

## iOS

Open `ios/Deepro.xcodeproj` with Xcode, select the owner's development team and signing profile, then archive/export using the intended distribution method. Signing certificates and provisioning profiles are not included. Device and distribution acceptance is performed separately from compilation.
