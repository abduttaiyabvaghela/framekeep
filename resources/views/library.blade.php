<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Framekeep · Media Library</title>
    <link rel="stylesheet" href="{{ asset('assets/style.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/library.css') }}">
    <script type="module" src="{{ asset('assets/library.js') }}"></script>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <a class="brand" href="{{ route('home') }}"><span class="brand-icon" aria-hidden="true">◎</span><span>Framekeep<small>MEDIA LIBRARY</small></span></a>
        <nav class="workspace-nav" aria-label="Workspace">
            <a href="#source-panel">Add a source</a>
            <a href="#library-panel">Library</a>
        </nav>
    </aside>
    <main>
        <header class="top"><strong>Media library</strong><div><button id="plans" type="button" class="button subtle">Plans</button>
            <button id="account" type="button" class="button primary">Sign in</button></div></header>
        <div class="content">
            <section class="hero">
                <div class="hero-copy">
                    <h1>Keep the media you need</h1>
                    <p>Find videos and images across platforms. Your library updates as each import runs.</p>
                </div>
                <img class="library-hero-image" src="{{ asset('assets/sample/alpine.jpg') }}" alt="">
            </section>
            <section class="sources" id="source-panel" aria-labelledby="source-title">
                <div class="source-head"><div><h2 id="source-title">Add a source</h2><p id="source-hint">Enter an Instagram username.</p></div></div>
                <form id="source-form" class="source-row">
                    <label class="source-input-group">Platform
                        <select id="platform">
                            <option value="instagram">Instagram</option>
                            <option value="instagram_pro" id="instagram-pro-option" hidden>Connected Instagram</option>
                            <option value="youtube">YouTube</option>
                            <option value="x">X</option>
                            <option value="pinterest">Pinterest</option>
                        </select>
                    </label>
                    <label class="source-input-group grow">Source
                        <input id="source" autocomplete="off" placeholder="@username" required>
                    </label>
                    <label class="source-input-group grow" id="instagram-edge-group" hidden>Media
                        <select id="instagram-edge"><option value="media">Posts and reels</option><option value="stories">Active stories</option></select>
                    </label>
                    <button class="button primary" type="submit">Fetch media</button>
                </form>
                <div id="instagram-actions" hidden>
                    <button type="button" class="button subtle" id="instagram-connect">Connect Instagram</button>
                    <button type="button" class="button subtle" id="instagram-disconnect" hidden>Disconnect Instagram</button>
                </div>
                <p id="source-result" class="hint" role="status" hidden></p>
            </section>
            <section id="library-panel" class="library" aria-labelledby="library-title">
                <header class="library-head">
                    <div><h2 id="library-title">Library</h2><p id="library-summary">Sign in and choose a source to start.</p></div>
                    <button id="download-all" class="button primary" disabled type="button">Download all</button>
                </header>
                <div class="toolbar">
                    <label class="source-input-group">Import <select id="imports"><option value="">No imports yet</option></select></label>
                    <label class="source-input-group">Media type <select id="filter">
                        <option value="">All media</option><option value="photo">Photos</option><option value="video">Videos</option>
                        <option value="reel">Reels</option><option value="story">Stories</option>
                    </select></label>
                </div>
                <div class="selection-row"><label><input id="select-visible" type="checkbox" disabled> Select visible</label>
                    <button id="download-selected" class="button plain" disabled type="button">Download selected <span id="selection-count">0</span></button></div>
                <div id="gallery" class="gallery" aria-live="polite"></div>
                <p id="empty" class="empty">No media has been imported yet.</p>
                <div class="library-more"><button id="load-more" class="button subtle" hidden type="button">Load more</button>
                    <button id="more-imports" class="button subtle" hidden type="button">Older imports</button></div>
                <section id="export-status" class="sources" aria-live="polite" hidden>
                    <p id="export-message"></p><a id="export-link" class="button primary" hidden>Download ZIP</a>
                </section>
            </section>
        </div>
    </main>
</div>
<dialog id="account-dialog">
    <div class="dialog-head"><div><h2 id="account-title">Sign in to Framekeep</h2><p id="account-description"></p></div>
        <button type="button" class="icon-button" data-close aria-label="Close">×</button></div>
    <form id="account-form" class="account-form">
        <label for="email">Email</label><input id="email" type="email" autocomplete="email" required maxlength="254">
        <label for="password">Password</label><input id="password" type="password" autocomplete="current-password" required maxlength="72">
        <div class="dialog-actions"><button type="button" class="button subtle" id="change-auth-mode">Create account</button>
            <button type="submit" class="button primary" id="submit-account">Sign in</button></div>
    </form>
    <div id="account-actions" class="dialog-actions" hidden><button id="logout" type="button" class="button subtle">Sign out</button></div>
    <p id="account-message" class="form-status" role="status"></p>
</dialog>
<dialog id="plans-dialog">
    <div class="dialog-head"><div><h2>Framekeep Pro</h2><p id="offer">USD $20 per year</p></div>
        <button type="button" class="icon-button" data-close aria-label="Close">×</button></div>
    <ul><li>Import from multiple sources</li><li>Download selected files or an entire import as a ZIP</li></ul>
    <p id="checkout-status" class="form-status" role="status">Checkout is currently disabled.</p>
    <div class="dialog-actions"><button id="checkout" class="button primary" type="button" hidden>Choose annual Pro</button></div>
</dialog>
<p id="notice" class="notice" role="status" hidden></p>
</body>
</html>
