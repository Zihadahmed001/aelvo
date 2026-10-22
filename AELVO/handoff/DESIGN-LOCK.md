# Design continuity contract — current as of 13 October 2026

Do not redesign AELVO or restart visual approval. The user accepted the current frontend and wants all remaining production features in the SAME design.

## Authority and precedence

1. Latest explicit user instructions in this handoff and future direct instructions.
2. This design-lock document for later colour/navigation/spacing changes.
3. Actual `dist/` implementation and assets as the portable visual and interaction baseline.
4. `APPROVED-DESIGN-AND-RULES.md` for screen coverage and detailed business requirements; its old monochrome and navigation descriptions are superseded below.
5. Master V5 for full functionality; older ORIGINAL-SPEC is background, not a smaller replacement.

## Preserve

- AELVO wordmark/brand and premium fashion layout; shoes, socks, belts, wallets and bags. Bangladesh, BDT, Bangla/English storefront.
- Soft sage/mint surfaces, warm off-white canvas, muted green emphasis. Do not revert to all black/white or make the green strip and Ask AELVO launcher saturated/dark again.
- Current CSS baseline: accent #466b5c; tint #e1e5e0; tint foreground #111; border #c4cec7; canvas #f5f5f0. Runtime theme settings can override these; retain readable contrast.
- Hero image, copy, CTA and footer caption in nonoverlapping flow. Banner grows with translated/long text. Do not reinstate the old fixed-height/absolutely positioned caption conflict.
- Subtle hover lift, shadow/colour feedback across storefront/admin buttons and clickable elements. Keyboard focus must remain visible. Disabled elements do not look actionable. Reduced-motion disables decorative movement.
- Desktop storefront navigation, product cards, existing product photography/illustration assets, product information, related products, reviews, Buy It Now and colour-specific galleries.
- Header search opens in-place with suggestions. Mobile bottom tabs: Home, Shop, Bag in the centre, Wishlist, Account. Preserve the liquid indicator and safe bottom spacing.
- Buy It Now has separate checkout items and does not overwrite the regular bag. Added-to-bag confirmation opens Bag when clicked.
- Admin layouts in `dist/assets/admin.js`, `configuration.js`, `editors.js`, `management.js`, `access.js`, with responsive cards/tables, uniform record buttons and permission-aware actions.
- Welcome popup and image/video hero controlled through Admin drafts and explicit publication. Video needs poster, pause and mobile/reduced-motion fallback.
- Customer assistant responds in Bengali/Banglish/English matching the latest customer message. Handoff pauses automated responses.

## Files to study before implementing PHP layout parity

`dist/index.html`; `dist/assets/styles.css`; `ui.js`; `storefront.js`; `admin.js`; `configuration.js`; `commerce.js`; `engagement.js`; `editors.js`; `management.js`; `i18n.js`; `access.js`; `app.js`; and the bundled product image assets.

`data.js` holds fictional fixtures, NOT production store records. `app.js` contains demonstration mutations and must not be treated as authorization/payment logic. Connect matching screens to server-side services and remove demo shortcuts from the production entry point. Preserve the independent visual reference so regressions can be compared.

Current `cpanel/public/store.php` and `app/AdminView.php` are incomplete functional scaffolds. Their simpler layout is NOT permission to replace the approved design. No new public publishing or live deployment is requested for this handoff.
