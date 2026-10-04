<template>
<svg  viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" v-bind="$attrs">

    <!-- NOT FOUND ICON -->
    <template v-if="icon == 'not-found'">
        <path class="table-result__notfound-color" d="M21 13V11C21 7.73081 21 6.09621 20.3272 4.86413C19.8219 3.93873 19.0613 3.17807 18.1359 2.67277C16.9038 2 15.2692 2 12 2V2C8.73081 2 7.09621 2 5.86413 2.67277C4.93873 3.17807 4.17807 3.93873 3.67277 4.86413C3 6.09621 3 7.73081 3 11V12.5C3 16.2433 3 18.1149 3.87311 19.4735C4.29935 20.1368 4.86322 20.7006 5.52648 21.1269C6.88505 22 8.7567 22 12.5 22V22H13" stroke="#222628"/>
        <line class="table-result__notfound-color" x1="15.5" y1="13.5" x2="7.5" y2="13.5"/>
        <line class="table-result__notfound-color" x1="15.5" y1="10.5" x2="7.5" y2="10.5"/>
        <line class="table-result__notfound-color" x1="11.5" y1="7.5" x2="7.5" y2="7.5"/>
        <rect :class="classname" class="table-result__notfound-color" x="14.8335" y="14.8335" width="7.08333" height="7.08333" rx="3.54167"/>
        <line :class="classname" class="table-result__notfound-color" x1="21.1803" y1="21.3066" x2="22.5565" y2="22.6829"/>
    </template>
    <!-- END NOT FOUND ICON -->

    <!-- NEXT ICON -->
    <template v-if="icon == 'next'">
        <path :class="classname" d="M10 16.5C10 16.5 14 12.5 14 12C14 11.5 10 7.5 10 7.5"/>
    </template>

    <template v-if="icon == 'link'">
        <path :class="classname" d="M10 13C10.4295 13.5741 10.9774 14.0491 11.6066 14.3929C12.2357 14.7367 12.9315 14.9411 13.6467 14.9923C14.3618 15.0435 15.0796 14.9403 15.7513 14.6897C16.4231 14.4392 17.0331 14.047 17.54 13.54L20.54 10.54C21.4508 9.59695 21.9548 8.33394 21.9434 7.02296C21.932 5.71198 21.4061 4.45791 20.4791 3.53087C19.5521 2.60383 18.298 2.07799 16.987 2.0666C15.676 2.0552 14.413 2.55918 13.47 3.46997L11.75 5.17997"/>
        <path :class="classname" d="M14.0002 11.0002C13.5707 10.4261 13.0228 9.95104 12.3936 9.60729C11.7645 9.26353 11.0687 9.05911 10.3535 9.00789C9.63841 8.95667 8.92061 9.05986 8.24885 9.31044C7.5771 9.56103 6.96709 9.95316 6.4602 10.4602L3.4602 13.4602C2.54941 14.4032 2.04544 15.6662 2.05683 16.9772C2.06822 18.2882 2.59407 19.5423 3.52111 20.4693C4.44815 21.3964 5.70221 21.9222 7.01319 21.9336C8.32418 21.945 9.58719 21.441 10.5302 20.5302L12.2402 18.8202"/>
    </template>

    <template v-if="icon == 'copy'">
        <rect :class="classname" x="1" y="2" width="17" height="16" rx="2"/>
        <path :class="classname" d="M18 4H18.4C19.8999 4 20.6498 4 21.1756 4.38197C21.3454 4.50533 21.4947 4.65464 21.618 4.82443C22 5.35016 22 6.10011 22 7.6V18.4C22 19.8999 22 20.6498 21.618 21.1756C21.4947 21.3454 21.3454 21.4947 21.1756 21.618C20.6498 22 19.9012 22 18.404 22C15.5371 22 11.3886 22 7.59963 22C6.09998 22 5.35016 22 4.82443 21.618C4.65464 21.4947 4.50533 21.3454 4.38197 21.1756C4 20.6498 4 19.8999 4 18.4V18"/>
    </template>

    <template v-if="icon == 'qr'">
        <rect :class="classname" x="14" y="2" width="8" height="8" rx="2"/>
        <rect :class="extraclass" x="14" y="13" width="2" height="2"/>
        <rect :class="extraclass" x="14" y="19" width="2" height="2"/>
        <rect :class="extraclass" x="17" y="16" width="2" height="2"/>
        <rect :class="extraclass" x="20" y="13" width="2" height="2"/>
        <rect :class="extraclass" x="20" y="19" width="2" height="2"/>
        <rect :class="classname"  x="2" y="2" width="8" height="8" rx="2"/>
        <rect :class="classname"  x="2" y="13" width="8" height="8" rx="2"/>
    </template>

    <template v-if="icon == 'info'">
        <path d="M22 12C22 17.5228 17.5228 22 12 22V22C6.47715 22 2 17.5228 2 12V12C2 6.47715 6.47715 2 12 2V2C17.5228 2 22 6.47715 22 12V12Z" fill="#FFC107"/>
        <path d="M11.2165 16.6722C11.2165 16.2396 11.5672 15.8889 11.9999 15.8889C12.4325 15.8889 12.7832 16.2396 12.7832 16.6722C12.7832 17.1049 12.4325 17.4556 11.9999 17.4556C11.5672 17.4556 11.2165 17.1049 11.2165 16.6722Z" fill="white"/>
        <path d="M12 13.6665L12 8.11095" stroke="white" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
    </template>
</svg>
</template>

<script>
export default {
    props: ['icon', 'classname', 'extraclass'],
}
</script>
