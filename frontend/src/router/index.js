function compilePath(path) {
  const keys = [];
  const regexText = path
    .replace(/\//g, '\\/')
    .replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, (_match, key) => {
      keys.push(key);
      return '([^\\/]+)';
    });

  return {
    keys,
    regex: new RegExp(`^${regexText}$`),
  };
}

export class Router {
  constructor(routes, onChange) {
    this.routes = routes.map((route) => ({
      ...route,
      ...compilePath(route.path),
    }));
    this.onChange = onChange;
  }

  current() {
    const raw = window.location.hash.replace(/^#/, '') || '/dashboard';
    const [pathPart, queryPart] = raw.split('?');
    const path = pathPart.startsWith('/') ? pathPart : `/${pathPart}`;
    const query = Object.fromEntries(new URLSearchParams(queryPart || ''));

    for (const route of this.routes) {
      const match = path.match(route.regex);
      if (!match) continue;

      const params = {};
      route.keys.forEach((key, index) => {
        params[key] = decodeURIComponent(match[index + 1] || '');
      });

      return {
        route,
        params,
        query,
        path,
      };
    }

    return {
      route: this.routes.find((item) => item.path === '/404'),
      params: {},
      query,
      path,
    };
  }

  navigate(path) {
    const normalized = path.startsWith('/') ? path : `/${path}`;
    window.location.hash = `#${normalized}`;
  }

  start() {
    window.addEventListener('hashchange', () => this.onChange(this.current()));
    window.addEventListener('load', () => this.onChange(this.current()));
    this.onChange(this.current());
  }
}
