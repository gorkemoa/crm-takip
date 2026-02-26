#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const projectRoot = path.resolve(__dirname, '..');
const srcDir = path.join(__dirname, 'src');
const distDir = path.join(__dirname, 'dist');
const distAssetsDir = path.join(distDir, 'assets');
const publicDir = path.join(projectRoot, 'public');

function ensureDir(dir) {
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

function removeDir(dir) {
  if (fs.existsSync(dir)) {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

function copyRecursive(source, target) {
  const stats = fs.statSync(source);

  if (stats.isDirectory()) {
    ensureDir(target);
    for (const entry of fs.readdirSync(source)) {
      copyRecursive(path.join(source, entry), path.join(target, entry));
    }
    return;
  }

  ensureDir(path.dirname(target));
  fs.copyFileSync(source, target);
}

function fileHash(filePath) {
  const content = fs.readFileSync(filePath);
  return crypto.createHash('sha256').update(content).digest('hex').slice(0, 10);
}

function writeFile(target, content) {
  ensureDir(path.dirname(target));
  fs.writeFileSync(target, content, 'utf8');
}

removeDir(distDir);
ensureDir(distAssetsDir);
copyRecursive(srcDir, distAssetsDir);

const entryJs = path.join(distAssetsDir, 'app.js');
const entryCssDir = path.join(distAssetsDir, 'styles');
const entryCss = path.join(entryCssDir, 'index.css');

if (!fs.existsSync(entryJs) || !fs.existsSync(entryCss)) {
  console.error('Build failed: entry files not found.');
  process.exit(1);
}

const jsHash = fileHash(entryJs);
const cssHash = fileHash(entryCss);

const hashedJs = `app-${jsHash}.js`;
const hashedCss = `index-${cssHash}.css`;

fs.renameSync(entryJs, path.join(distAssetsDir, hashedJs));
fs.renameSync(entryCss, path.join(entryCssDir, hashedCss));

const indexHtml = `<!doctype html>
<html lang="tr">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Crm Takip</title>
    <meta name="description" content="Proje ve iş akışı takip uygulaması" />
    <link rel="stylesheet" href="./assets/styles/${hashedCss}" />
  </head>
  <body>
    <div id="app"></div>
    <script type="module" src="./assets/${hashedJs}"></script>
  </body>
</html>
`;

writeFile(path.join(distDir, 'index.html'), indexHtml);
writeFile(
  path.join(distDir, 'manifest.json'),
  JSON.stringify(
    {
      entry: {
        js: `assets/${hashedJs}`,
        css: `assets/styles/${hashedCss}`,
      },
    },
    null,
    2
  )
);

if (fs.existsSync(path.join(publicDir, '.htaccess'))) {
  fs.copyFileSync(path.join(publicDir, '.htaccess'), path.join(distDir, '.htaccess'));
}

removeDir(path.join(publicDir, 'assets'));
copyRecursive(distAssetsDir, path.join(publicDir, 'assets'));
fs.copyFileSync(path.join(distDir, 'index.html'), path.join(publicDir, 'index.html'));

console.log('Offline production build complete.');
console.log('Dist:', distDir);
console.log('Public synced:', publicDir);
