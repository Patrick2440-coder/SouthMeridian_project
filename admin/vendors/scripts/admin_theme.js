/* ============================================================
   SOUTH MERIDIAN HOMES
   ADMIN DARK MODE
   ============================================================ */

(function () {

    'use strict';

    const STORAGE_KEY = 'hoa-theme';
    const root = document.documentElement;


    /* ========================================================
       CURRENT THEME
       ======================================================== */

    function isDark() {
        return root.classList.contains('dark');
    }


    /* ========================================================
       SAVE THEME
       ======================================================== */

    function saveTheme(theme) {

        try {
            localStorage.setItem(
                STORAGE_KEY,
                theme
            );
        } catch (e) {
            console.warn('Unable to save theme preference.');
        }

    }


    /* ========================================================
       UPDATE ICON
       ======================================================== */

    function updateToggle() {

        const button =
            document.getElementById('themeToggle');

        const icon =
            document.getElementById('themeIcon');


        if (!button || !icon) {
            return;
        }


        if (isDark()) {

            icon.textContent = '☀';

            button.title =
                'Switch to light mode';

            button.setAttribute(
                'aria-label',
                'Switch to light mode'
            );

        } else {

            icon.textContent = '☾';

            button.title =
                'Switch to dark mode';

            button.setAttribute(
                'aria-label',
                'Switch to dark mode'
            );

        }

    }


    /* ========================================================
       REFRESH CHART.JS
       ======================================================== */

    function refreshCharts() {

        if (
            typeof Chart === 'undefined' ||
            typeof Chart.getChart !== 'function'
        ) {
            return;
        }


        const dark = isDark();

        const textColor =
            dark
                ? '#cbd5e1'
                : '#475569';


        const gridColor =
            dark
                ? 'rgba(148,163,184,.16)'
                : 'rgba(100,116,139,.15)';


        document
            .querySelectorAll('canvas')
            .forEach(function (canvas) {

                const chart =
                    Chart.getChart(canvas);


                if (!chart) {
                    return;
                }


                try {

                    if (
                        chart.options.plugins &&
                        chart.options.plugins.legend
                    ) {

                        chart.options.plugins.legend.labels =
                            chart.options.plugins.legend.labels || {};

                        chart.options.plugins.legend.labels.color =
                            textColor;

                    }


                    if (chart.options.scales) {

                        Object.keys(
                            chart.options.scales
                        ).forEach(function (key) {

                            const scale =
                                chart.options.scales[key];


                            if (!scale) {
                                return;
                            }


                            scale.ticks =
                                scale.ticks || {};

                            scale.ticks.color =
                                textColor;


                            scale.grid =
                                scale.grid || {};

                            scale.grid.color =
                                gridColor;


                            if (scale.title) {
                                scale.title.color =
                                    textColor;
                            }

                        });

                    }


                    chart.update();

                } catch (error) {

                    console.warn(
                        'Chart theme refresh skipped.',
                        error
                    );

                }

            });

    }


    /* ========================================================
       APPLY THEME
       ======================================================== */

    function applyTheme(theme, save = true) {

        const dark =
            theme === 'dark';


        root.classList.toggle(
            'dark',
            dark
        );


        if (save) {
            saveTheme(
                dark
                    ? 'dark'
                    : 'light'
            );
        }


        updateToggle();


        setTimeout(
            refreshCharts,
            10
        );


        try {

            window.dispatchEvent(
                new CustomEvent(
                    'admin-theme-change',
                    {
                        detail: {
                            theme:
                                dark
                                    ? 'dark'
                                    : 'light'
                        }
                    }
                )
            );

        } catch (e) {}

    }


    /* ========================================================
       TOGGLE THEME
       ======================================================== */

    function toggleTheme() {

        if (isDark()) {

            applyTheme('light');

        } else {

            applyTheme('dark');

        }

    }


    /* ========================================================
       INITIALIZE
       ======================================================== */

    function initialize() {

        const button =
            document.getElementById('themeToggle');


        if (!button) {

            console.error(
                'Admin dark mode: #themeToggle was not found.'
            );

            return;

        }


        /*
         * Update correct moon/sun before interaction.
         */
        updateToggle();


        /*
         * Direct click handler.
         */
        button.addEventListener(
            'click',
            function (event) {

                event.preventDefault();
                event.stopPropagation();

                toggleTheme();

            }
        );


        /*
         * Also protect against theme button being
         * dynamically recreated.
         */
        document.addEventListener(
            'click',
            function (event) {

                const target =
                    event.target.closest
                        ? event.target.closest('#themeToggle')
                        : null;


                if (!target) {
                    return;
                }


                if (target === button) {
                    return;
                }


                event.preventDefault();

                toggleTheme();

            }
        );


        /*
         * Dashboard creates the chart later.
         */
        setTimeout(
            refreshCharts,
            500
        );

    }


    if (
        document.readyState === 'loading'
    ) {

        document.addEventListener(
            'DOMContentLoaded',
            initialize
        );

    } else {

        initialize();

    }


    /* ========================================================
       GLOBAL API
       ======================================================== */

    window.AdminTheme = {

        toggle: toggleTheme,

        dark: function () {
            applyTheme('dark');
        },

        light: function () {
            applyTheme('light');
        },

        isDark: isDark,

        refresh: function () {
            updateToggle();
            refreshCharts();
        }

    };


})();