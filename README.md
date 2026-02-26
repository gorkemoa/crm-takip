# Crm Takip (Vanilla JS + PHP + MySQL + SSE)

Sade, kurumsal bir **proje & iş akışı takip** uygulaması.

Bu repo, ozellikle:
- CRM lisans maliyeti yuksek gelen kucuk ekipler
- Tek kisilik gelistirici ekipleri
- Erken asama startup'lar
icin ucretsiz, acik kaynak bir alternatif sunmak icin yayinlanmistir.

- Frontend: HTML + CSS + Vanilla JS (SPA, component yaklaşımı, store, modal/toast, skeleton)
- Backend: PHP 8 (frameworksüz mini API)
- Database: MySQL (InfinityFree uyumlu)
- Real-time: SSE (Server-Sent Events) + Notification Center

## Özellikler

- Kullanıcı adı + parola giriş
- Açık kayıt kapalı; kullanıcıları admin Ayarlar sayfasından oluşturur
- Session tabanlı auth (HttpOnly cookie) + CSRF
- Proje yönetimi (liste, filtre, detay, üyeler, activity)
- Proje durumları Türkçe ve detaylıdır (`Başlanıyor`, `Üzerinde Tartışılıyor`, `Araştırılıyor`, `Tasarlanıyor`, `Geliştiriliyor`, `Test Ediliyor`, `Yayına Hazır`, `Yayında`, `Bakımda`, `Duraklatıldı`, `İptal Talebi`, `İptal Edildi`, `Tamamlandı`)
- Pipeline aşamaları (detaylı durum, saat tahmini/harcanan, admin custom stage)
- Task yönetimi (Türkçe durumlu kanban, atama, durum, yorum)
- Projeye çoklu ekip üyesi ekleme/çıkarma ve üye bazlı durum takibi
- Fikir havuzu (idea CRUD, tek tıkla projeye dönüştürme)
- Audit log (kim neyi ne zaman değiştirdi)
- Bildirim sistemi (`notifications` + `events`) ve canlı SSE akışı
- Opsiyonel Browser Notification API desteği (izin verildiğinde)

## Lisans

Bu proje [MIT License](./LICENSE) ile lisanslanmistir.

## Klasör Yapısı

```text
/public
  index.html
  /assets/*                  # production build çıktısı
  /api/index.php             # backend public entry wrapper
  /api/events/stream/index.php
/frontend
  /src
    /components
    /pages
    /router
    /store
    /styles
    /utils
    app.js
  build.js
  index.html
  vite.config.js
  package.json
  README.md
/api
  index.php
  events_stream.php
  /lib
  /migrations
  /scripts/init.php
/data
  app_mysql.sql
```

## Kurulum

1. Veritabanı + migration + seed:

```bash
php api/scripts/init.php
```

Isterseniz `.env.example` dosyasini `.env` olarak kopyalayip DB ayarlarinizi da buradan verebilirsiniz.

2. Uygulamayı çalıştır:

```bash
php -S localhost:8000 -t public
```

3. Tarayıcıda aç:

- `http://localhost:8000`

## Demo Kullanıcılar

- Admin:
  - Kullanıcı adı: `admin`
  - Parola: `Admin123!`

Not: Canli kullanimda ilk giristen sonra admin parolasini degistirin.

Seed ile ayrıca:
- 1 örnek proje
- 2 örnek fikir

## API Yanıt Formatı

Tüm endpoint’ler bu JSON formatını döner:

```json
{
  "ok": true,
  "data": {},
  "error": null
}
```

Hata formatı:

```json
{
  "ok": false,
  "data": null,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Form doğrulama hatası.",
    "fields": {
      "email": "Geçerli bir e-posta girin."
    }
  }
}
```

## Önemli Route Notları

- SPA hash tabanlıdır (fallback ihtiyacını azaltmak için):
  - `#/login`, `#/projects`, `#/projects/:id`, `#/ideas`, `#/settings`, `#/notifications`
- API base:
  - `/api/index.php/...`
- SSE stream:
  - `/api/events/stream/`

## Güvenlik

- `password_hash` / `password_verify`
- Prepared statements (PDO)
- Login rate limit (IP bazlı)
- Session + CSRF doğrulama
- RBAC (admin/member)

## Frontend Build

Vite ile production build:

```bash
npm install --prefix frontend
npm run build
```

Bu komut `frontend/dist` üretir ve `public/index.html` + `public/assets/*` dosyalarını otomatik senkronlar.

Offline build (npm install yoksa):

```bash
node frontend/build.js
```

Bu komut `frontend/dist` üretir ve `public/` ile senkronlar.

## InfinityFree / MySQL Kurulumu

1. `data/app_mysql.sql` dosyasını phpMyAdmin ile import et.
2. `api/config/database.php.example` dosyasını `api/config/database.php` olarak kopyala.
3. `api/config/database.php` içine InfinityFree MySQL bilgilerini yaz:

```bash
host: sqlXXX.epizy.com
port: 3306
dbname: epiz_xxxxxx_crm
user: epiz_xxxxxx
pass: ********
```

Alternatif olarak `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` environment değişkenleri de kullanılabilir.

## Acik Kaynak Olarak GitHub'da Yayınlama

Asagidaki adimlarla projeyi `gorkemoa35@gmail.com` hesabina bagli GitHub profiline acik kaynak olarak koyabilirsin.

1. GitHub'da yeni bir **public** repo olustur.
   - Repo onerisi: `crm-takip`
2. Proje klasorunde su komutlari calistir:

```bash
cd /Users/admin/Documents/crm_rv
git init
git add .
git commit -m "Initial open source release"
git branch -M main
git remote add origin https://github.com/gorkemoa35/crm-takip.git
git push -u origin main
```

3. GitHub repo ayarlari:
   - Visibility: `Public`
   - Topics: `crm`, `php`, `vanilla-js`, `sse`, `startup`, `project-management`
   - Issues: `Enabled`
   - Discussions: `Enabled` (opsiyonel)

4. Yayina cikmadan once kontrol:
   - `api/config/database.php` dosyasini repoya dahil etme (zaten `.gitignore` ile disarida)
   - `data/app.db` dosyasini repoya dahil etme
   - Gerekirse demo sifreleri degistir

## Topluluk ve Katki

- Katki rehberi: [CONTRIBUTING.md](./CONTRIBUTING.md)
- Davranis kurallari: [CODE_OF_CONDUCT.md](./CODE_OF_CONDUCT.md)
- Guvenlik bildirimi: [SECURITY.md](./SECURITY.md)
