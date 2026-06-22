# iPhone web app и быстрый первый запуск

Реализация добавляет:

- PWA manifest и иконки 180/192/512;
- standalone-режим iOS;
- PWA работает без перехвата навигации service worker: на iOS он вызывал WebKit `unknown error` до обращения к серверу;
- безопасную скользящую сессию на 30 дней;
- защиту session cookie: Secure, HttpOnly, SameSite=Lax;
- смену session ID после входа.

Для nginx должны быть включены gzip и долгий immutable-кэш `/assets/`. `sw.js` и `manifest.webmanifest`, напротив, отдаются с `no-cache`.

После выкладки старую иконку на iPhone лучше удалить и добавить сайт на экран «Домой» заново: iOS кэширует manifest и apple-touch-icon независимо от Safari.
