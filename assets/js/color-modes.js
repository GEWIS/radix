/*!
 * Color mode toggler for Bootstrap's docs (https://getbootstrap.com/)
 * Copyright 2011-2023 The Bootstrap Authors
 * Licensed under the Creative Commons Attribution 3.0 Unported License.
 */

/*
 * Inlined in the head so the theme is on the document before the first paint. Only what has to happen that early is
 * here; the switcher buttons are bound by the `theme-switcher` Stimulus controller, which goes through the
 * `window.gewisTheme` API below so the storage key and the `auto` rule are written down once.
 */
(() => {
    'use strict'

    const getStoredTheme = () => localStorage.getItem('theme')
    const setStoredTheme = theme => localStorage.setItem('theme', theme)

    const getPreferredTheme = () => {
        const storedTheme = getStoredTheme()
        if (storedTheme) {
            return storedTheme
        }

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'gewis-night' : 'gewis-day'
    }

    const setTheme = theme => {
        if (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.setAttribute('data-bs-theme', 'gewis-night')
        } else {
            document.documentElement.setAttribute('data-bs-theme', theme)
        }
    }

    setTheme(getPreferredTheme())

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        const storedTheme = getStoredTheme()
        if (storedTheme !== 'gewis-day' && storedTheme !== 'gewis-night') {
            setTheme(getPreferredTheme())
        }
    })

    window.gewisTheme = {
        preferred: getPreferredTheme,
        apply: setTheme,
        store: setStoredTheme,
    }
})()
