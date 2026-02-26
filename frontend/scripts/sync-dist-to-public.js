#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const frontendDir = path.resolve(__dirname, '..');
const projectRoot = path.resolve(frontendDir, '..');
const distDir = path.join(frontendDir, 'dist');
const publicDir = path.join(projectRoot, 'public');

const distIndex = path.join(distDir, 'index.html');
const distAssets = path.join(distDir, 'assets');
const distManifest = path.join(distDir, 'manifest.json');

function exists(filePath) {
  return fs.existsSync(filePath);
}

function ensureDir(dirPath) {
  if (!exists(dirPath)) {
    fs.mkdirSync(dirPath, { recursive: true });
  }
}

function removeDir(dirPath) {
  if (exists(dirPath)) {
    fs.rmSync(dirPath, { recursive: true, force: true });
  }
}

function copyRecursive(source, target) {
  const stat = fs.statSync(source);
  if (stat.isDirectory()) {
    ensureDir(target);
    for (const entry of fs.readdirSync(source)) {
      copyRecursive(path.join(source, entry), path.join(target, entry));
    }
    return;
  }

  ensureDir(path.dirname(target));
  fs.copyFileSync(source, target);
}

if (!exists(distIndex) || !exists(distAssets)) {
  console.error('Sync failed: frontend/dist production output not found. Run "npm --prefix frontend run build" first.');
  process.exit(1);
}

ensureDir(publicDir);
removeDir(path.join(publicDir, 'assets'));
copyRecursive(distAssets, path.join(publicDir, 'assets'));
fs.copyFileSync(distIndex, path.join(publicDir, 'index.html'));

if (exists(distManifest)) {
  fs.copyFileSync(distManifest, path.join(publicDir, 'manifest.json'));
}

console.log('Synced frontend/dist -> public');
