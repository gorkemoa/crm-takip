export async function render(ctx) {
  if (ctx.state.user) {
    ctx.router.navigate('/dashboard');
    return { title: 'Yönlendiriliyor', html: '' };
  }

  return {
    title: 'Kayıt Kapalı',
    html: `
      <section class="auth-wrap">
        <div class="auth-card">
          <h1>Kayıt Akışı Kapalı</h1>
          <p>Bu Crm Takip uygulamasında yeni kullanıcılar sadece admin tarafından <strong>Ayarlar</strong> ekranından oluşturulur.</p>
          <a class="btn btn-primary" href="#/login">Giriş Sayfasına Dön</a>
        </div>
      </section>
    `,
  };
}
