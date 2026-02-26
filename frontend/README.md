# Frontend (Vanilla SPA + Vite)

Bu klasör React benzeri SPA mimarisinin kaynak kodunu içerir.

## Yapı

- `src/router`: hash tabanlı SPA router
- `src/store`: subscribe/publish state store
- `src/components`: modal, toast ve UI yardımcıları
- `src/pages`: uygulama sayfaları
- `src/utils`: API client, tarih ve DOM yardımcıları
- `src/styles`: design token + layout + component stilleri

## Development

```bash
cd frontend
npm install
npm run dev
```

## Production Build

Vite build (önerilen):

```bash
cd frontend
npm install
npm run build
```

Çıktı: `frontend/dist/` (hash'li assetler)

`public/` klasörünü otomatik güncellemek için:

```bash
cd frontend
npm run build:sync
```

## Offline Build (npm install yoksa)

```bash
node frontend/build.js
```

Bu komut:
- `frontend/dist/` üretir (hash'li assetler)
- `public/index.html` ve `public/assets/*` dosyalarını production çıktısıyla senkronlar
