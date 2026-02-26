# Katki Rehberi

Bu proje, kucuk ekipler, tek kisilik ekipler ve startup'lar icin ucretsiz CRM alternatifi olmasi amaciyla acik kaynak olarak paylasilmistir.

## Gelistirme Akisi

1. Bu depoyu fork edin.
2. Yeni bir branch olusturun:
   - `feat/...` (yeni ozellik)
   - `fix/...` (hata duzeltme)
   - `docs/...` (dokumantasyon)
3. Degisiklikleri yapin ve test edin.
4. Pull Request acin.

## Kod Standartlari

- Frontend: Vanilla JS + CSS + HTML (framework eklemeyin).
- Backend: Frameworksuz PHP 8 + PDO.
- API cevabi formati korunmali: `{ ok, data, error }`.
- Hazir UI kit/library eklemeyin.
- Turkiye Turkcesi metinlerde anlasilirlik oncelikli olmalidir.

## PR Kontrol Listesi

- [ ] Ozellik/hata aciklamasi net
- [ ] Geriye donuk uyumluluk bozulmadi
- [ ] Build alindi (`node frontend/build.js` veya `npm run build`)
- [ ] README gerekiyorsa guncellendi

## Iletisim

Buyuk degisiklikler icin once Issue acarak tartisma baslatin.
