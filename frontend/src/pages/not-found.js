export async function render() {
  return {
    title: '404',
    html: `
      <section class="empty-screen">
        <h1>404</h1>
        <p>Aradığınız sayfa bulunamadı.</p>
        <a class="btn btn-primary" href="#/dashboard">Dashboard'a Dön</a>
      </section>
    `,
  };
}
