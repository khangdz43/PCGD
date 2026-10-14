<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <meta name="turbo-prefetch" content="false">
    @vite('resources/js/app.js')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelector('meta[name="viewport"]')?.setAttribute(
                'content',
                'width=device-width, initial-scale=1.0',
            );
        }, { once: true });
    </script>
    <title>@yield('title', 'PCGD-XMC - Phổ cập giáo dục')</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome (Icons) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <style>
        .app-breadcrumb-wrap {
            background: #f1f5f9;
            border-bottom: 1px solid #dbe3ed;
        }

        .app-breadcrumb {
            margin: 0;
            padding: .7rem 1rem;
            background: #fff;
            border: 1px solid #dbe3ed;
            border-left: 4px solid #0d6efd;
            border-radius: .5rem;
            box-shadow: 0 .125rem .35rem rgb(15 23 42 / 8%);
            --bs-breadcrumb-divider: '›';
        }

        .app-breadcrumb .breadcrumb-item,
        .app-breadcrumb .breadcrumb-item a {
            font-size: 1rem;
        }

        .app-breadcrumb .breadcrumb-item a {
            display: inline-block;
            padding: .15rem .25rem;
            color: #0a58ca;
            font-weight: 600;
            text-decoration: none;
            border-radius: .25rem;
        }

        .app-breadcrumb .breadcrumb-item a:hover,
        .app-breadcrumb .breadcrumb-item a:focus-visible {
            color: #084298;
            background: #e7f1ff;
            text-decoration: underline;
        }

        .app-breadcrumb .breadcrumb-item.active {
            color: #212529;
            font-weight: 700;
        }

        .app-notifications {
            display: grid;
            gap: .65rem;
            width: min(100%, 30rem);
            margin: 0;
        }

        .app-notifications-wrap {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: grid;
            place-items: center;
            padding: 1rem;
            pointer-events: none;
            visibility: hidden;
            opacity: 0;
            transition: opacity .18s ease, visibility .18s ease;
        }

        .app-notifications-wrap:has(.app-notification),
        .app-notifications-wrap.is-visible {
            visibility: visible;
            opacity: 1;
        }

        .app-notifications-wrap.is-toast,
        .app-notifications-wrap:has(.app-notifications-toast) {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: flex-start;
            gap: .65rem;
            padding: 1rem;
        }

        .app-notification {
            --notice-color: #2563eb;
            --notice-bg: #eff6ff;
            --notice-border: #bfdbfe;
            display: flex;
            align-items: flex-start;
            gap: .8rem;
            padding: .9rem 1rem;
            border: 1px solid var(--notice-border);
            border-left: 4px solid var(--notice-color);
            border-radius: .75rem;
            background: var(--notice-bg);
            color: #1e293b;
            box-shadow: 0 1rem 3rem rgb(15 23 42 / 20%);
            pointer-events: auto;
            animation: app-notification-enter .2s ease-out both;
            transition: opacity .2s ease, transform .2s ease;
        }

        @keyframes app-notification-enter {
            from { opacity: 0; transform: translateY(.5rem) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .app-notification-success {
            --notice-color: #15803d;
            --notice-bg: #f0fdf4;
            --notice-border: #bbf7d0;
        }

        .app-notification-warning {
            --notice-color: #b45309;
            --notice-bg: #fffbeb;
            --notice-border: #fde68a;
        }

        .app-notification-danger {
            --notice-color: #b91c1c;
            --notice-bg: #fef2f2;
            --notice-border: #fecaca;
        }

        .app-notification-info {
            --notice-color: #0369a1;
            --notice-bg: #f0f9ff;
            --notice-border: #bae6fd;
        }

        .app-notification-icon {
            display: grid;
            width: 2rem;
            height: 2rem;
            flex: 0 0 2rem;
            place-items: center;
            border-radius: 50%;
            background: color-mix(in srgb, var(--notice-color) 12%, white);
            color: var(--notice-color);
        }

        .app-notification-content {
            min-width: 0;
            flex: 1 1 auto;
        }

        .app-notification-title {
            margin: .1rem 0 .2rem;
            color: #0f172a;
            font-size: .95rem;
            font-weight: 700;
        }

        .app-notification-message {
            margin: 0;
            overflow-wrap: anywhere;
            font-size: .9rem;
        }

        .app-notification-details {
            margin: .45rem 0 0;
            padding-left: 1.15rem;
            font-size: .875rem;
        }

        .app-notification-close {
            display: grid;
            width: 2rem;
            height: 2rem;
            flex: 0 0 2rem;
            place-items: center;
            border: 0;
            border-radius: .5rem;
            background: transparent;
            color: #475569;
        }

        .app-notification-close:hover {
            background: rgb(15 23 42 / 7%);
        }

        .app-notification.is-hiding {
            opacity: 0;
            transform: translateY(.35rem) scale(.98);
        }

        .app-confirm-dialog {
            width: min(28rem, calc(100vw - 2rem));
            max-width: none;
            padding: 1.25rem;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            color: #1e293b;
            box-shadow: 0 1.25rem 3.5rem rgb(15 23 42 / 28%);
        }

        .app-confirm-dialog::backdrop {
            background: rgb(15 23 42 / 42%);
            backdrop-filter: blur(2px);
        }

        .app-confirm-heading {
            display: flex;
            align-items: center;
            gap: .7rem;
            margin-bottom: .65rem;
        }

        .app-confirm-icon {
            display: grid;
            width: 2.5rem;
            height: 2.5rem;
            flex: 0 0 2.5rem;
            place-items: center;
            border-radius: 50%;
            background: #fff7ed;
            color: #c2410c;
        }

        .app-confirm-title {
            margin: 0;
            color: #0f172a;
            font-size: 1.05rem;
            font-weight: 700;
        }

        .app-confirm-message {
            margin: 0 0 1.25rem;
            overflow-wrap: anywhere;
            line-height: 1.55;
        }

        .app-confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: .55rem;
        }

        @media (max-width: 575.98px) {
            .app-notifications {
                width: min(100%, 24rem);
            }

            .app-notification {
                gap: .6rem;
                padding: .8rem;
            }

            .app-confirm-actions .btn {
                flex: 1 1 0;
            }
        }

        .app-loading-alert {
            position: fixed;
            z-index: 1090;
            top: 1rem;
            left: 50%;
            display: flex;
            align-items: center;
            gap: .65rem;
            max-width: calc(100vw - 2rem);
            padding: .65rem 1rem;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            background: #fff;
            color: #1e40af;
            box-shadow: 0 .5rem 1.25rem rgb(15 23 42 / 16%);
            opacity: 0;
            pointer-events: none;
            transform: translate(-50%, -.5rem);
            transition: opacity .18s ease, transform .18s ease;
        }

        .app-loading-alert.is-visible {
            opacity: 1;
            transform: translate(-50%, 0);
        }

        .app-loading-alert .spinner-border {
            width: 1rem;
            height: 1rem;
            flex: 0 0 1rem;
            border-width: .15em;
        }

        .app-navbar .dropdown-menu:not(.show) {
            display: none;
        }

        .app-navbar .dropdown-menu.show {
            display: block;
        }

        .app-inline-note {
            display: flex;
            align-items: flex-start;
            gap: .65rem;
            padding: .75rem .9rem;
            border: 1px solid #bae6fd;
            border-left: 3px solid #0284c7;
            border-radius: .6rem;
            background: #f0f9ff;
            color: #334155;
            font-size: .9rem;
        }

        .app-inline-note > i {
            margin-top: .15rem;
            color: #0369a1;
        }
    </style>

    @stack('styles')
    <style>
        html {
            min-width: 320px;
            scroll-padding-top: 1rem;
            scrollbar-gutter: stable;
        }

        body {
            overflow-x: clip;
        }

        .app-header-content,
        .app-brand,
        .app-account {
            min-width: 0;
        }

        .app-brand-title {
            font-size: clamp(1rem, 2vw, 1.5rem);
            line-height: 1.25;
        }

        .app-account {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: .75rem;
        }

        .app-account-card {
            display: flex;
            align-items: center;
            gap: .65rem;
            min-width: 0;
            max-width: 36rem;
            padding: .4rem .75rem;
            border: 1px solid #e2e8f0;
            border-radius: .75rem;
            background: #f8fafc;
        }

        .app-account-avatar {
            display: grid;
            width: 2.35rem;
            height: 2.35rem;
            flex: 0 0 2.35rem;
            place-items: center;
            border-radius: .65rem;
            background: #dbeafe;
            color: #1d4ed8;
        }

        .app-account-details {
            min-width: 0;
        }

        .app-account-name {
            display: flex;
            align-items: center;
            gap: .45rem;
            min-width: 0;
            color: #0f172a;
            font-size: .92rem;
            font-weight: 700;
            line-height: 1.3;
            text-decoration: none;
        }

        .app-account-name span {
            overflow-wrap: anywhere;
        }

        .app-account-name i {
            flex: 0 0 auto;
            color: #64748b;
            font-size: .75rem;
        }

        .app-account-name:hover {
            color: #1d4ed8;
        }

        .app-account-location {
            display: flex;
            align-items: flex-start;
            gap: .35rem;
            margin-top: .15rem;
            color: #64748b;
            font-size: .78rem;
            line-height: 1.35;
        }

        .app-account-location i {
            flex: 0 0 auto;
            margin-top: .1rem;
            color: #0d6efd;
        }

        .app-account-location span {
            overflow-wrap: anywhere;
        }

        .app-logout-form {
            flex: 0 0 auto;
        }

        .app-logout-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            min-height: 2.6rem;
            padding: .45rem .75rem;
            border: 1px solid #cbd5e1;
            border-radius: .65rem;
            background: #fff;
            color: #475569;
            font-size: .875rem;
            font-weight: 600;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease;
        }

        .app-logout-button:hover {
            border-color: #fecaca;
            background: #fef2f2;
            color: #b91c1c;
        }

        .navbar-toggler {
            min-width: 2.75rem;
            min-height: 2.75rem;
        }

        .navbar-nav .nav-link,
        .navbar-nav .dropdown-item {
            overflow-wrap: anywhere;
        }

        main {
            min-width: 0;
        }

        main > .container,
        main > .container-fluid {
            min-width: 0;
        }

        .table-responsive {
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive > .table {
            margin-bottom: 0;
        }

        .location-picker-field,
        .directory-filter-field {
            min-width: 0;
        }

        .table th,
        .table td {
            vertical-align: middle;
        }

        .pagination {
            flex-wrap: wrap;
            gap: .2rem;
        }

        .pagination .page-link {
            min-width: 2.5rem;
            text-align: center;
        }

        .form-control,
        .form-select {
            min-width: 0;
        }

        button,
        a {
            touch-action: manipulation;
        }

        :focus-visible {
            outline: 3px solid rgb(13 110 253 / 45%);
            outline-offset: 2px;
        }

        @media (max-width: 991.98px) {
            .app-header-content {
                flex-wrap: wrap;
                row-gap: .65rem;
            }

            .app-brand {
                flex: 1 1 100%;
            }

            .app-brand-title {
                max-width: 100%;
            }

            .app-account {
                flex: 1 1 100%;
                justify-content: space-between;
                gap: .75rem !important;
            }

            .app-account-card {
                flex: 1 1 auto;
            }

            .navbar-collapse {
                max-height: calc(100dvh - 5rem);
                overflow-y: auto;
                overscroll-behavior: contain;
            }

            .navbar-nav .nav-link {
                min-height: 2.75rem;
                display: flex;
                align-items: center;
            }

            .navbar-nav .dropdown-menu {
                margin: 0 0 .5rem .75rem;
            }

            .navbar-nav .dropdown-item {
                display: flex;
                min-height: 2.75rem;
                align-items: center;
            }

            .tree-node.level-commune,
            .tree-node.level-village {
                margin-left: .75rem;
            }

            .organization-tree {
                max-height: 55vh !important;
            }
        }

        @media (max-width: 991.98px) {
            .app-navbar {
                position: relative;
                z-index: 1030;
            }

            .app-navbar > .container {
                position: relative;
            }

            .app-navbar .navbar-collapse {
                position: absolute;
                top: calc(100% + .35rem);
                right: .75rem;
                left: .75rem;
                z-index: 1031;
                max-height: calc(100dvh - 7rem);
                padding: .5rem;
                overflow-y: auto;
                border: 1px solid #dbe3ed;
                border-radius: .75rem;
                background: #fff;
                box-shadow: 0 .75rem 2rem rgb(15 23 42 / 18%);
            }

            .app-navbar .navbar-nav {
                gap: .15rem;
            }

            .app-navbar .navbar-nav .nav-link {
                min-height: 2.8rem;
                padding: .65rem .75rem;
                border-radius: .5rem;
                color: #334155;
                font-weight: 600;
            }

            .app-navbar .navbar-nav .nav-link:hover,
            .app-navbar .navbar-nav .nav-link:focus-visible,
            .app-navbar .navbar-nav .nav-link.active {
                background: #eff6ff;
                color: #0a58ca;
            }

            .app-navbar .navbar-nav .dropdown-menu {
                margin: 0 0 .35rem .75rem;
                padding: .25rem;
                border: 0;
                border-left: 2px solid #bfdbfe;
                border-radius: 0;
                box-shadow: none;
            }

            .app-navbar .navbar-nav .dropdown-item {
                min-height: 2.65rem;
                padding: .6rem .75rem;
                border-radius: .4rem;
                color: #475569;
            }

            .app-navbar .navbar-nav .dropdown-item:hover,
            .app-navbar .navbar-nav .dropdown-item.active {
                background: #eff6ff;
                color: #0a58ca;
            }
        }

        @media (max-width: 767.98px) {
            main.py-4 {
                padding-top: .75rem !important;
                padding-bottom: .75rem !important;
            }

            main > .container,
            main > .container-fluid {
                padding-right: 1rem;
                padding-left: 1rem;
            }

            .app-breadcrumb {
                padding: .55rem .75rem;
            }

            .app-breadcrumb .breadcrumb-item,
            .app-breadcrumb .breadcrumb-item a {
                font-size: .9rem;
            }

            .directory-filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .directory-filter-form .directory-filter-field:first-child {
                grid-column: 1 / -1;
            }

            .directory-filter-form .directory-filter-clear {
                min-height: 2.25rem;
                width: 100%;
            }

            .person-survey-form .form-control,
            .person-survey-form .form-select {
                min-height: 2.75rem;
                font-size: 1rem;
            }

            .table-responsive > .table:not(.member-table) {
                min-width: 38rem;
            }

            .household-table-scroll > .table {
                min-width: 54rem;
                table-layout: fixed;
            }

            .household-table-scroll > .table th,
            .household-table-scroll > .table td {
                white-space: nowrap;
            }

            .household-table-scroll > .table td.household-code-cell > span {
                display: block;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .household-table-scroll > .table td.household-head-cell {
                white-space: normal;
                overflow-wrap: anywhere;
            }

            .household-table-scroll > .table th:nth-child(1),
            .household-table-scroll > .table td:nth-child(1) {
                width: 3.5rem;
            }

            .household-table-scroll > .table th:nth-child(2),
            .household-table-scroll > .table td:nth-child(2) {
                width: 8rem;
            }

            .household-table-scroll > .table th:nth-child(3),
            .household-table-scroll > .table td:nth-child(3) {
                width: 13rem;
            }

            .household-table-scroll > .table th:nth-child(4),
            .household-table-scroll > .table td:nth-child(4) {
                width: 16rem;
            }

            .household-table-scroll > .table th:nth-child(5),
            .household-table-scroll > .table td:nth-child(5) {
                width: 6rem;
            }

            .household-table-scroll > .table th:nth-child(6),
            .household-table-scroll > .table td:nth-child(6) {
                width: 7.5rem;
            }

            .household-table-hint {
                display: flex;
                align-items: center;
                gap: .4rem;
                color: #64748b;
                font-size: .8rem;
            }

            .household-bulk-actions .btn,
            .member-browser-actions form,
            .member-browser-actions .btn {
                width: 100%;
            }

            .member-browser-heading {
                align-items: flex-start !important;
            }

            .member-browser-actions {
                width: 100%;
                align-items: stretch !important;
                flex-direction: column;
            }

            .member-browser-actions #member-selection-summary {
                text-align: left;
            }

            .tree-header,
            .scope-header {
                gap: .55rem;
                padding: .7rem;
            }

            .tree-body-inner,
            .scope-body-inner {
                padding-right: .65rem;
                padding-left: .65rem;
            }

            .organization-tree .card-body {
                padding: .5rem !important;
            }
        }

        @media (max-width: 575.98px) {
            .app-header-content {
                padding-top: .25rem;
                padding-bottom: .25rem;
            }

            .app-brand {
                gap: .65rem !important;
            }

            .app-brand-mark {
                width: 42px !important;
                height: 42px !important;
                font-size: .8rem;
            }

            .app-brand-title {
                font-size: .95rem;
            }

            .app-brand-subtitle {
                display: block;
                font-size: .75rem;
                line-height: 1.25;
            }

            .app-account {
                align-items: stretch;
            }

            .app-account-card {
                padding: .4rem .55rem;
            }

            .app-account-location {
                font-size: .75rem;
            }

            .app-logout-button {
                min-height: 100%;
                padding-right: .6rem;
                padding-left: .6rem;
            }

            .app-navbar .navbar-collapse {
                right: .5rem;
                left: .5rem;
            }

            main > .container,
            main > .container-fluid {
                padding-right: .75rem;
                padding-left: .75rem;
            }

            main h1.h1,
            main h1.h2,
            main h1.h3 {
                font-size: 1.35rem;
                line-height: 1.3;
            }

            main .card-body,
            main section.p-4 {
                padding: 1rem !important;
            }

            .container > .d-flex.justify-content-between {
                align-items: stretch !important;
            }

            .container > .d-flex.justify-content-between > .btn,
            .container > .d-flex.justify-content-between > .d-flex {
                width: 100%;
            }

            .container > .d-flex.justify-content-between > .d-flex {
                flex-wrap: wrap;
            }

            .container > .d-flex.justify-content-between > .d-flex > .btn {
                flex: 1 1 auto;
            }

            main .btn:not(.btn-sm) {
                min-height: 2.75rem;
            }

            main .btn-sm {
                min-width: 2.5rem;
                min-height: 2.5rem;
            }

            main form .form-control,
            main form .form-select,
            main form .form-control-sm,
            main form .form-select-sm {
                min-height: 2.75rem;
                font-size: 1rem;
            }

            main form input[type="checkbox"],
            main form input[type="radio"] {
                min-width: 1.25rem;
                min-height: 1.25rem;
            }

            main input:not([type="checkbox"]):not([type="radio"]):not([type="range"]),
            main select,
            main textarea {
                font-size: 16px !important;
            }

            .table {
                font-size: .875rem;
            }

            .member-table-scroll > .member-table {
                min-width: 75rem;
            }

            .tree-controls {
                justify-content: stretch !important;
            }

            .tree-controls .btn {
                flex: 1 1 0;
                min-height: 2.5rem;
            }

            .role-card,
            .user-card {
                gap: .6rem;
                padding: .7rem;
            }

            .role-card .role-info,
            .user-card .user-info {
                min-width: 0;
            }

            .role-card .role-name,
            .user-card .user-name {
                overflow-wrap: anywhere;
            }

            .pagination .page-link {
                min-width: 2.25rem;
                padding: .4rem .55rem;
            }

            .pagination .page-item:first-child .page-link,
            .pagination .page-item:last-child .page-link {
                padding-right: .55rem;
                padding-left: .55rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
    @include('partials.password-toggle')
</head>

<body class="bg-light">
    <div class="app-loading-alert" id="app-loading-alert" role="status" aria-live="polite" aria-hidden="true">
        <span class="spinner-border text-primary" aria-hidden="true"></span>
        <span>Đang tải, vui lòng đợi...</span>
    </div>
    <dialog class="app-confirm-dialog" id="app-confirm-dialog" aria-labelledby="app-confirm-title" aria-describedby="app-confirm-message">
        <div class="app-confirm-heading">
            <span class="app-confirm-icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <h2 class="app-confirm-title" id="app-confirm-title">Xác nhận thao tác</h2>
        </div>
        <p class="app-confirm-message" id="app-confirm-message"></p>
        <div class="app-confirm-actions">
            <button class="btn btn-outline-secondary" type="button" data-confirm-cancel>Để sau</button>
            <button class="btn btn-danger" type="button" data-confirm-accept>Xác nhận</button>
        </div>
    </dialog>

    {{-- Header --}}
    @include('partials.header')

    {{-- Navbar --}}
    @include('partials.navbar')

    {{-- Content --}}
    <main class="py-4">
        @include('partials.breadcrumbs')
        <div class="app-notifications-wrap">
            @include('partials.notifications')
        </div>
        @yield('content')
    </main>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" data-turbo-eval="false"></script>
    <script>
        (() => {
            if (window.bootstrap?.Dropdown || window.appNavbarFallbackInitialized) return;
            window.appNavbarFallbackInitialized = true;

            const closeDropdowns = (except = null) => {
                document.querySelectorAll('.app-navbar .dropdown-menu.show').forEach((menu) => {
                    if (menu === except) return;
                    menu.classList.remove('show');
                    menu.closest('.dropdown')?.querySelector('[data-bs-toggle="dropdown"]')
                        ?.setAttribute('aria-expanded', 'false');
                });
            };

            document.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) return;

                const dropdownToggle = target.closest('.app-navbar [data-bs-toggle="dropdown"]');
                if (dropdownToggle) {
                    event.preventDefault();
                    const menu = dropdownToggle.closest('.dropdown')?.querySelector(':scope > .dropdown-menu');
                    if (!menu) return;

                    const opening = !menu.classList.contains('show');
                    closeDropdowns(menu);
                    menu.classList.toggle('show', opening);
                    dropdownToggle.setAttribute('aria-expanded', String(opening));
                    return;
                }

                const collapseToggle = target.closest('.app-navbar [data-bs-toggle="collapse"]');
                if (collapseToggle) {
                    event.preventDefault();
                    const selector = collapseToggle.getAttribute('data-bs-target');
                    const collapse = selector ? document.querySelector(selector) : null;
                    if (!collapse) return;

                    const expanded = collapse.classList.toggle('show');
                    collapseToggle.setAttribute('aria-expanded', String(expanded));
                    return;
                }

                if (!target.closest('.app-navbar .dropdown')) closeDropdowns();
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') closeDropdowns();
            });
        })();
    </script>
    @stack('scripts')
    <script>
        (() => {
            let loadingTimer;
            let isNavigating = false;

            const showLoadingAlert = () => {
                const loadingAlert = document.getElementById('app-loading-alert');
                window.clearTimeout(loadingTimer);
                loadingAlert?.classList.add('is-visible');
                loadingAlert?.setAttribute('aria-hidden', 'false');
            };

            const updateLoadingAlert = () => {
                const loadingAlert = document.getElementById('app-loading-alert');
                if (isNavigating) {
                    loadingTimer = window.setTimeout(showLoadingAlert, 180);
                    return;
                }

                window.clearTimeout(loadingTimer);
                loadingAlert?.classList.remove('is-visible');
                loadingAlert?.setAttribute('aria-hidden', 'true');
            };

            if (!window.appLoadingAlertInitialized) {
                document.addEventListener('turbo:before-visit', () => {
                    isNavigating = true;
                    updateLoadingAlert();
                });
                document.addEventListener('turbo:load', () => {
                    isNavigating = false;
                    updateLoadingAlert();
                });
                window.appLoadingAlertInitialized = true;
            }

            if (!window.appLayoutEventsBound) {
                document.addEventListener('invalid', (event) => {
                    const field = event.target;
                    if (!(field instanceof HTMLElement) || !field.matches('input[required], select[required], textarea[required]')) return;

                    field.classList.add('is-invalid');
                    let feedback = field.nextElementSibling;
                    if (!feedback?.matches('.invalid-feedback')) {
                        feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback d-block';
                        feedback.dataset.requiredFeedback = 'true';
                        field.insertAdjacentElement('afterend', feedback);
                    }

                    if (!feedback.textContent.trim()) {
                        const label = field.id ? document.querySelector(`label[for="${CSS.escape(field.id)}"]`) : null;
                        const labelText = label?.textContent.replace('*', '').trim().toLowerCase();
                        feedback.textContent = field.validity.valueMissing ?
                            `${field.tagName === 'SELECT' ? 'Vui lòng chọn' : 'Vui lòng nhập'} ${labelText || 'thông tin này'}.` :
                            field.validationMessage;
                    }
                }, true);

                function clearRequiredFieldError(event) {
                    const field = event.target;
                    if (!(field instanceof HTMLElement) || !field.matches('input[required], select[required], textarea[required]') || !field.checkValidity()) return;

                    field.classList.remove('is-invalid');
                    if (field.nextElementSibling?.dataset.requiredFeedback) {
                        field.nextElementSibling.remove();
                    }
                }

                document.addEventListener('input', clearRequiredFieldError, true);
                document.addEventListener('change', clearRequiredFieldError, true);
                window.appLayoutEventsBound = true;
            }
            if (!window.appNotificationsInitialized) {
                document.addEventListener('turbo:load', () => {
                    document.querySelectorAll('.app-notification').forEach((notification) => {
                        const closeButton = notification.querySelector('.app-notification-close');
                        if (!closeButton || closeButton.dataset.initialized) return;

                        const dismiss = () => {
                            notification.classList.add('is-hiding');
                            window.setTimeout(() => notification.remove(), 220);
                        };

                        closeButton.addEventListener('click', dismiss);
                        closeButton.dataset.initialized = 'true';
                        if (notification.hasAttribute('data-notification-autohide')) {
                            window.setTimeout(dismiss, 6500);
                        }
                    });
                });
                window.appNotificationsInitialized = true;
            }

            window.showAppNotification = (type, message, title, action = null) => {
                const notificationTypes = {
                    success: { title: 'Thao tác thành công', icon: 'fa-circle-check', className: 'success' },
                    info: { title: 'Thông tin', icon: 'fa-circle-info', className: 'info' },
                    warning: { title: 'Cần lưu ý', icon: 'fa-triangle-exclamation', className: 'warning' },
                    error: { title: 'Thao tác chưa thành công', icon: 'fa-circle-exclamation', className: 'danger' },
                };
                const typeInfo = notificationTypes[type] || notificationTypes.info;
                const notificationTitle = title || typeInfo.title;
                const notificationKey = JSON.stringify([
                    typeInfo.className,
                    notificationTitle,
                    message,
                    action?.href || '',
                    action?.label || '',
                ]);
                const duplicate = Array.from(document.querySelectorAll('.app-notification'))
                    .some((item) => item.dataset.notificationKey === notificationKey);
                if (duplicate) return;

                const container = document.querySelector('.app-notifications-wrap');
                if (!container) return;
                container.classList.add('is-visible');
                if (type === 'success' || action) {
                    container.classList.add('is-toast');
                }
                const notification = document.createElement('div');
                const icon = document.createElement('span');
                const iconElement = document.createElement('i');
                const content = document.createElement('div');
                const heading = document.createElement('h2');
                const body = document.createElement('p');
                const closeButton = document.createElement('button');
                const closeIcon = document.createElement('i');

                notification.className = `app-notification app-notification-${typeInfo.className}`;
                notification.setAttribute('role', type === 'error' || type === 'warning' ? 'alert' : 'status');
                notification.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
                icon.className = 'app-notification-icon';
                icon.setAttribute('aria-hidden', 'true');
                iconElement.className = `fa-solid ${typeInfo.icon}`;
                icon.appendChild(iconElement);
                content.className = 'app-notification-content';
                heading.className = 'app-notification-title';
                heading.textContent = title || typeInfo.title;
                body.className = 'app-notification-message';
                body.textContent = message;
                content.append(heading, body);
                if (action?.href && action?.label) {
                    const actionLink = document.createElement('a');
                    actionLink.className = 'btn btn-sm btn-outline-primary mt-2';
                    actionLink.href = action.href;
                    actionLink.textContent = action.label;
                    content.appendChild(actionLink);
                }
                closeButton.className = 'app-notification-close';
                closeButton.type = 'button';
                closeButton.setAttribute('aria-label', 'Đóng thông báo');
                closeIcon.className = 'fa-solid fa-xmark';
                closeIcon.setAttribute('aria-hidden', 'true');
                closeButton.appendChild(closeIcon);
                notification.dataset.notificationKey = notificationKey;
                notification.append(icon, content, closeButton);
                container.appendChild(notification);

                const dismiss = () => {
                    notification.classList.add('is-hiding');
                    window.setTimeout(() => {
                        notification.remove();
                        if (!container.querySelector('.app-notification')) {
                            container.classList.remove('is-visible');
                            container.classList.remove('is-toast');
                        }
                    }, 220);
                };
                closeButton.addEventListener('click', dismiss);
                if ((type === 'success' || type === 'info') && !action) {
                    window.setTimeout(dismiss, 4000);
                }
                if (action && (type === 'success' || type === 'error')) {
                    window.setTimeout(dismiss, 6000);
                }
            };

            window.alert = (message) => window.showAppNotification('info', String(message));

            @auth
                if (!window.householdImportNotificationsInitialized) {
                    window.householdImportNotificationsInitialized = true;
                    const notificationUrl = @json(route('households.import-notifications', [], false));
                    const acknowledgeUrl = @json(route('households.import-notifications.acknowledge', [], false));
                    const csrfToken = @json(csrf_token());
                    const displayedIds = new Set();
                    let checkingNotifications = false;

                    const checkHouseholdImportNotifications = async () => {
                        if (checkingNotifications || document.visibilityState !== 'visible') return;
                        checkingNotifications = true;

                        try {
                            const response = await fetch(notificationUrl, {
                                headers: { Accept: 'application/json' },
                                credentials: 'same-origin',
                            });
                            if (!response.ok) throw new Error('Không thể kiểm tra kết quả nhập dữ liệu.');

                            const payload = await response.json();
                            if (document.visibilityState !== 'visible') return;
                            const notifications = Array.isArray(payload.notifications) ? payload.notifications : [];
                            const ids = [];

                            notifications.forEach((item) => {
                                ids.push(item.id);
                                if (displayedIds.has(item.id)) return;
                                displayedIds.add(item.id);
                                const failed = item.status === 'FAILED';
                                window.showAppNotification(
                                    failed ? 'error' : 'success',
                                    item.message,
                                    failed ? 'Nhập dữ liệu thất bại' : 'Nhập dữ liệu thành công',
                                    { href: item.action_url, label: item.action_label },
                                );
                            });

                            if (ids.length > 0) {
                                const acknowledgeResponse = await fetch(acknowledgeUrl, {
                                    method: 'POST',
                                    headers: {
                                        Accept: 'application/json',
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': csrfToken,
                                    },
                                    body: JSON.stringify({ ids }),
                                    credentials: 'same-origin',
                                });
                                if (!acknowledgeResponse.ok) throw new Error('Không thể ghi nhận thông báo kết quả nhập.');
                            }
                        } catch (error) {
                            console.warn(error);
                        } finally {
                            checkingNotifications = false;
                        }
                    };

                    checkHouseholdImportNotifications();
                    window.setInterval(checkHouseholdImportNotifications, 2000);
                    document.addEventListener('visibilitychange', () => {
                        if (document.visibilityState === 'visible') {
                            checkHouseholdImportNotifications();
                        }
                    });
                }
            @endauth

            const confirmDialog = document.getElementById('app-confirm-dialog');
            const confirmMessage = document.getElementById('app-confirm-message');
            const confirmButton = confirmDialog?.querySelector('[data-confirm-accept]');
            const cancelButton = confirmDialog?.querySelector('[data-confirm-cancel]');
            let confirmQueue = Promise.resolve();
            let resolveConfirmation;

            confirmDialog?.addEventListener('close', () => {
                resolveConfirmation?.(confirmDialog.returnValue === 'confirm');
                resolveConfirmation = undefined;
            });
            confirmButton?.addEventListener('click', () => confirmDialog.close('confirm'));
            cancelButton?.addEventListener('click', () => confirmDialog.close('cancel'));

            const showConfirmation = (message, options = {}) => new Promise((resolve) => {
                if (!confirmDialog || !confirmMessage || !confirmButton || !cancelButton) {
                    resolve(false);
                    return;
                }

                const title = confirmDialog.querySelector('#app-confirm-title');
                confirmMessage.textContent = String(message);
                title.textContent = options.title || 'Xác nhận thao tác';
                confirmButton.textContent = options.confirmText || 'Xác nhận';
                confirmButton.classList.toggle('btn-danger', options.variant !== 'primary');
                confirmButton.classList.toggle('btn-primary', options.variant === 'primary');

                resolveConfirmation = resolve;
                confirmDialog.showModal();
                cancelButton.focus();
            });

            window.appConfirm = (message, options = {}) => {
                const pending = confirmQueue.then(() => showConfirmation(message, options));
                confirmQueue = pending.then(() => undefined, () => undefined);
                return pending;
            };

            if (!window.appConfirmFormsInitialized) {
                document.addEventListener('submit', (event) => {
                    const form = event.target;
                    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;

                    event.preventDefault();
                    window.appConfirm(form.dataset.confirm, {
                        title: form.dataset.confirmTitle,
                        confirmText: form.dataset.confirmButton || 'Xác nhận',
                        variant: form.dataset.confirmVariant,
                    }).then((confirmed) => {
                        if (!confirmed || !form.isConnected) return;
                        HTMLFormElement.prototype.submit.call(form);
                    }).catch((error) => {
                        console.error('Không thể xác nhận thao tác:', error);
                        window.showAppNotification('error', 'Không thể xác nhận thao tác. Vui lòng tải lại trang và thử lại.');
                    });
                }, true);
                document.addEventListener('change', (event) => {
                    const select = event.target.closest('[data-pagination-select]');
                    if (!select || event.defaultPrevented || select.closest('[data-ajax-pagination]')) return;
                    select.form?.requestSubmit();
                });
                window.appConfirmFormsInitialized = true;
            }
        })();
    </script>
</body>

</html>