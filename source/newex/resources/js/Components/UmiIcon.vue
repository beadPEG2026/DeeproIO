<template>
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path :d="paths[name] || paths.arrow" />
</svg>
</template>
<script>
import UmiLocale from '@/Functions/UmiLocale';
const paths = {
    stocks: 'M4 21V9h5v12M9 21V3h6v18M15 21V12h5v9M2 21h20M11 7h2m-2 4h2m-2 4h2',
    brain: 'M9 4a3 3 0 0 0-5 3 4 4 0 0 0-1 7 4 4 0 0 0 6 5V4Zm6 0a3 3 0 0 1 5 3 4 4 0 0 1 1 7 4 4 0 0 1-6 5V4ZM6 9l3 2m9-2-3 2M5 16l4-1m10 1-4-1M9 6h6M9 16h6',
    network: 'M10 2h4v4h-4V2ZM2 17h5v5H2v-5Zm15 0h5v5h-5v-5ZM10 17h4v5h-4v-5ZM12 6v5M4 17v-6h16v6M12 11v6',
    device: 'M5 2h14v20H5V2Zm3 4h8v8H8V6Zm0 12h3m4 0h1',
    document: 'M5 3h9l5 5v13H5V3Zm9 0v6h5M8 13h8m-8 4h5',

    home: 'M3 10 12 3l9 7v10H3V10Zm6 10v-7h6v7',
    market: 'M4 4v16h17M8 15l4-5 4 3 5-7',
    trade: 'M3 7h17l-4-4M21 17H4l4 4',
    futures: 'M5 19V9m7 10V4m7 15v-7M3 21h18',
    wallet: 'M20 7H5a2 2 0 0 1 0-4h13v4M5 7H3v14h18V7h-1Zm10 5h6v5h-6v-5Z',
    search: 'M10.5 17a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13ZM16 16l5 5',
    deposit: 'M12 3v13m-5-5 5 5 5-5M4 18v3h16v-3',
    withdraw: 'M12 16V3m-5 5 5-5 5 5M4 18v3h16v-3',
    user: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-3a5 5 0 0 1 5-5h6a5 5 0 0 1 5 5v3',
    support: 'M4 13v-2a8 8 0 0 1 16 0v2M4 11H2v7h4v-7H4Zm16 0h2v7h-4v-7h2Zm0 7v3h-7',
    bell: 'M5 16h14l-2-3V8a5 5 0 0 0-10 0v5l-2 3Zm5 4h4',
    chevron: 'm9 5 7 7-7 7',
    arrow: 'M4 12h16m-6-6 6 6-6 6',
    eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Zm13 0a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    megaphone: 'm3 10 15-5v14L3 14v-4Zm3 5 1 6h4l-2-5M21 8v8',
    star: 'm12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9L12 3Z',
    shield: 'm12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Zm-4 9 3 3 5-6',
    globe: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM3 12h18M12 3c-5 5-5 13 0 18 5-5 5-13 0-18Z',
};
export default {mixins:[UmiLocale], props: { name: String }, data: () => ({ paths }) };
</script>
