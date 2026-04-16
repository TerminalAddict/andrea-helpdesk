#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const OUT_DIR = path.join(__dirname, '..', 'docs', 'screenshots', 'install');
fs.mkdirSync(OUT_DIR, { recursive: true });

const W = 1440;
const H = 900;

function esc(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function svgDoc(body) {
  return `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
  <defs>
    <linearGradient id="pageBg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#f5f7fb"/>
      <stop offset="100%" stop-color="#edf2f7"/>
    </linearGradient>
    <linearGradient id="navBg" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%" stop-color="#182130"/>
      <stop offset="100%" stop-color="#141b28"/>
    </linearGradient>
    <linearGradient id="cardGlow" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#eaf7fb"/>
      <stop offset="100%" stop-color="#f7f8fc"/>
    </linearGradient>
    <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="14" stdDeviation="18" flood-color="#9fb3c8" flood-opacity="0.22"/>
    </filter>
  </defs>
  <rect width="${W}" height="${H}" fill="url(#pageBg)"/>
  ${body}
</svg>`;
}

function browserChrome(title = 'Andrea Helpdesk Installer') {
  return `
    <rect x="78" y="36" width="1284" height="44" rx="16" fill="#d8e4f8"/>
    <rect x="78" y="80" width="1284" height="784" rx="20" fill="#ffffff" filter="url(#shadow)"/>
    <circle cx="112" cy="58" r="7" fill="#f66"/>
    <circle cx="136" cy="58" r="7" fill="#f7c948"/>
    <circle cx="160" cy="58" r="7" fill="#50c878"/>
    <rect x="230" y="46" width="440" height="24" rx="12" fill="#eef3fb"/>
    <text x="250" y="63" font-family="Arial, Helvetica, sans-serif" font-size="13" fill="#52627a">${esc(title)}</text>
  `;
}

function topNav() {
  return `
    <rect x="78" y="80" width="1284" height="68" rx="20" fill="url(#navBg)"/>
    <rect x="78" y="128" width="1284" height="20" fill="url(#navBg)"/>
    <rect x="100" y="100" width="28" height="28" rx="6" fill="#8bc53f"/>
    <path d="M105 121 L105 106 L124 111 L124 121 Z" fill="#ffffff" opacity="0.92"/>
    <text x="140" y="120" font-family="Arial, Helvetica, sans-serif" font-size="24" font-weight="700" fill="#f4f7fb">Andrea Helpdesk</text>
  `;
}

function installerCard(title, subtitle = 'Installation Wizard') {
  return `
    <g transform="translate(0,0)">
      <circle cx="720" cy="228" r="28" fill="#e7f2ff"/>
      <text x="720" y="239" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="34" fill="#2563eb">◔</text>
      <text x="720" y="292" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="34" font-weight="700" fill="#243244">${esc(title)}</text>
      <text x="720" y="323" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#7b8898">${esc(subtitle)}</text>
      <rect x="360" y="348" width="720" height="420" rx="24" fill="url(#cardGlow)" stroke="#d9e3f0"/>
    </g>
  `;
}

function steps(activeIndex, labels) {
  const startX = 430;
  const y = 392;
  const gap = 128;
  let parts = '';
  labels.forEach((label, index) => {
    const x = startX + index * gap;
    const state = index < activeIndex ? 'done' : index === activeIndex ? 'active' : 'pending';
    const fill = state === 'done' ? '#16a34a' : state === 'active' ? '#2563eb' : '#d9dee8';
    const color = state === 'pending' ? '#697789' : '#ffffff';
    parts += `<circle cx="${x}" cy="${y}" r="17" fill="${fill}"/>`;
    parts += `<text x="${x}" y="${y + 5}" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="13" font-weight="700" fill="${color}">${state === 'done' ? '✓' : index + 1}</text>`;
    parts += `<text x="${x}" y="${y + 40}" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="12" fill="${state === 'active' ? '#2563eb' : '#7b8898'}">${esc(label)}</text>`;
    if (index < labels.length - 1) {
      const lineColor = index < activeIndex ? '#16a34a' : '#d9dee8';
      parts += `<rect x="${x + 17}" y="${y - 1}" width="${gap - 34}" height="2" fill="${lineColor}"/>`;
    }
  });
  return parts;
}

function reqRow(y, icon, iconColor, label, note) {
  return `
    <g>
      <circle cx="450" cy="${y}" r="10" fill="${iconColor}" opacity="0.15"/>
      <text x="450" y="${y + 4}" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="12" font-weight="700" fill="${iconColor}">${esc(icon)}</text>
      <text x="475" y="${y + 4}" font-family="Arial, Helvetica, sans-serif" font-size="15" font-weight="600" fill="#263445">${esc(label)}</text>
      <text x="845" y="${y + 4}" font-family="Arial, Helvetica, sans-serif" font-size="13" fill="#7b8898">${esc(note)}</text>
    </g>
  `;
}

function field(x, y, w, label, value, help = '') {
  return `
    <text x="${x}" y="${y}" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#314155">${esc(label)}</text>
    <rect x="${x}" y="${y + 12}" width="${w}" height="42" rx="10" fill="#ffffff" stroke="#d8e2ee"/>
    <text x="${x + 14}" y="${y + 39}" font-family="Arial, Helvetica, sans-serif" font-size="15" fill="#52627a">${esc(value)}</text>
    ${help ? `<text x="${x}" y="${y + 73}" font-family="Arial, Helvetica, sans-serif" font-size="12" fill="#8a97a8">${esc(help)}</text>` : ''}
  `;
}

function button(x, y, w, label, fill, textColor = '#fff') {
  return `
    <rect x="${x}" y="${y}" width="${w}" height="46" rx="12" fill="${fill}"/>
    <text x="${x + w / 2}" y="${y + 29}" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="16" font-weight="700" fill="${textColor}">${esc(label)}</text>
  `;
}

function resultRow(y, tone, label) {
  const map = {
    ok: ['#dbf5e5', '#147a45', '✓'],
    skip: ['#eef2f6', '#4f6074', '–'],
    warning: ['#fff4d8', '#996100', '!'],
  };
  const [bg, fg, icon] = map[tone];
  return `
    <rect x="420" y="${y}" width="600" height="38" rx="10" fill="${bg}"/>
    <circle cx="446" cy="${y + 19}" r="10" fill="${fg}" opacity="0.15"/>
    <text x="446" y="${y + 23}" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="12" font-weight="700" fill="${fg}">${icon}</text>
    <text x="466" y="${y + 24}" font-family="Arial, Helvetica, sans-serif" font-size="14" fill="#344457">${esc(label)}</text>
  `;
}

function terminalMock() {
  return svgDoc(`
    ${browserChrome('Andrea Helpdesk — Command Line Install')}
    <rect x="118" y="122" width="1204" height="692" rx="24" fill="#10151e" filter="url(#shadow)"/>
    <text x="152" y="168" font-family="Menlo, Consolas, monospace" font-size="19" fill="#9db5cf">paul@server:/var/www/andrea-helpdesk$</text>
    <text x="480" y="168" font-family="Menlo, Consolas, monospace" font-size="19" fill="#d8e7f5">composer install --no-dev --optimize-autoloader</text>
    <text x="152" y="215" font-family="Menlo, Consolas, monospace" font-size="16" fill="#90a8c2">Installing dependencies from lock file</text>
    <text x="152" y="240" font-family="Menlo, Consolas, monospace" font-size="16" fill="#90a8c2">Generating optimized autoload files</text>
    <text x="152" y="288" font-family="Menlo, Consolas, monospace" font-size="19" fill="#9db5cf">paul@server:/var/www/andrea-helpdesk$</text>
    <text x="480" y="288" font-family="Menlo, Consolas, monospace" font-size="19" fill="#d8e7f5">cp .env.example .env</text>
    <text x="152" y="336" font-family="Menlo, Consolas, monospace" font-size="19" fill="#9db5cf">paul@server:/var/www/andrea-helpdesk$</text>
    <text x="480" y="336" font-family="Menlo, Consolas, monospace" font-size="19" fill="#d8e7f5">php bin/migrate.php</text>
    <text x="152" y="383" font-family="Menlo, Consolas, monospace" font-size="16" fill="#8fe4a8">Connected to database: helpdesk</text>
    <text x="152" y="408" font-family="Menlo, Consolas, monospace" font-size="16" fill="#8fe4a8">Migration complete. Executed 21 statements.</text>
    <text x="152" y="456" font-family="Menlo, Consolas, monospace" font-size="19" fill="#9db5cf">paul@server:/var/www/andrea-helpdesk$</text>
    <text x="480" y="456" font-family="Menlo, Consolas, monospace" font-size="19" fill="#d8e7f5">php bin/seed.php</text>
    <text x="152" y="503" font-family="Menlo, Consolas, monospace" font-size="16" fill="#8fe4a8">Admin agent created:</text>
    <text x="184" y="528" font-family="Menlo, Consolas, monospace" font-size="16" fill="#8fe4a8">Name:  Andrea Admin</text>
    <text x="184" y="553" font-family="Menlo, Consolas, monospace" font-size="16" fill="#8fe4a8">Email: admin@example.test</text>
    <text x="184" y="578" font-family="Menlo, Consolas, monospace" font-size="16" fill="#8fe4a8">Role:  admin</text>
    <rect x="118" y="612" width="1204" height="146" rx="18" fill="#141d28" stroke="#243242"/>
    <text x="152" y="650" font-family="Arial, Helvetica, sans-serif" font-size="20" font-weight="700" fill="#f2f6fb">CLI Install Highlights</text>
    <text x="152" y="686" font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#c7d3e0">• Set APP_URL, JWT_SECRET, DB_* and STORAGE_PATH in .env before seeding the admin account.</text>
    <text x="152" y="714" font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#c7d3e0">• STORAGE_PATH should be outside public_html so attachments and logs are never web-accessible.</text>
    <text x="152" y="742" font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#c7d3e0">• After install, add bin/imap-poll.php to cron if you want inbound email polling and SLA checks.</text>
  `);
}

function ftpMock() {
  const leftRows = [
    '/Users/admin/Downloads/andrea-helpdesk',
    '├── bin',
    '├── config',
    '├── database',
    '├── docs',
    '├── public_html',
    '├── src',
    '└── vendor'
  ];
  const rightRows = [
    '/home/example/support.example.com',
    '├── public_html  ← document root',
    '│   ├── index.php',
    '│   ├── api.php',
    '│   ├── attachment.php',
    '│   └── assets',
    '├── src',
    '├── database',
    '├── vendor',
    '└── ../andrea-helpdesk-storage'
  ];
  return svgDoc(`
    ${browserChrome('FTP Upload Layout — Andrea Helpdesk')}
    <rect x="108" y="120" width="570" height="700" rx="22" fill="#ffffff" stroke="#d8e2ee" filter="url(#shadow)"/>
    <rect x="762" y="120" width="570" height="700" rx="22" fill="#ffffff" stroke="#d8e2ee" filter="url(#shadow)"/>
    <text x="136" y="164" font-family="Arial, Helvetica, sans-serif" font-size="24" font-weight="700" fill="#263445">Local Upload Source</text>
    <text x="790" y="164" font-family="Arial, Helvetica, sans-serif" font-size="24" font-weight="700" fill="#263445">Shared Hosting Destination</text>
    <rect x="136" y="190" width="514" height="48" rx="12" fill="#f4f7fb"/>
    <rect x="790" y="190" width="514" height="48" rx="12" fill="#f4f7fb"/>
    <text x="156" y="220" font-family="Arial, Helvetica, sans-serif" font-size="14" fill="#617085">Upload the full project plus vendor/</text>
    <text x="810" y="220" font-family="Arial, Helvetica, sans-serif" font-size="14" fill="#617085">Point the domain to public_html/ and keep storage outside it</text>
    ${leftRows.map((row, i) => `<text x="144" y="${282 + i * 42}" font-family="Menlo, Consolas, monospace" font-size="20" fill="${i === 0 ? '#1f2c3b' : '#4e5f74'}">${esc(row)}</text>`).join('')}
    ${rightRows.map((row, i) => `<text x="798" y="${282 + i * 42}" font-family="Menlo, Consolas, monospace" font-size="20" fill="${row.includes('←') ? '#157347' : i === 0 ? '#1f2c3b' : '#4e5f74'}">${esc(row)}</text>`).join('')}
    <path d="M700 360 C735 360, 740 360, 770 360" fill="none" stroke="#2563eb" stroke-width="8" stroke-linecap="round"/>
    <polygon points="760,344 798,360 760,376" fill="#2563eb"/>
    <path d="M700 520 C735 520, 740 520, 770 520" fill="none" stroke="#8bc53f" stroke-width="8" stroke-linecap="round"/>
    <polygon points="760,504 798,520 760,536" fill="#8bc53f"/>
    <rect x="448" y="650" width="540" height="118" rx="20" fill="#eef6ff" stroke="#d2e4ff"/>
    <text x="478" y="688" font-family="Arial, Helvetica, sans-serif" font-size="22" font-weight="700" fill="#23457b">FTP / Shared Hosting Notes</text>
    <text x="478" y="720" font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#50637a">1. Upload vendor/ if Composer is not available on the server.</text>
    <text x="478" y="748" font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#50637a">2. Create a writable storage path outside public_html before running /install/.</text>
  `);
}

function wizardRequirements() {
  return svgDoc(`
    ${browserChrome()}
    ${installerCard('Andrea Helpdesk', 'Installation Wizard')}
    ${steps(0, ['Requirements', 'Database', 'Settings', 'Install', 'Done'])}
    <text x="432" y="460" font-family="Arial, Helvetica, sans-serif" font-size="22" font-weight="700" fill="#243244">System Requirements</text>
    ${reqRow(502, '✓', '#16a34a', 'PHP ≥ 8.1', '8.4.20')}
    ${reqRow(542, '✓', '#16a34a', 'ext-pdo_mysql', '')}
    ${reqRow(582, '!', '#e0a106', 'ext-imap', 'optional for web install, required for email polling')}
    ${reqRow(622, '✓', '#16a34a', 'curl or allow_url_fopen', 'available')}
    ${reqRow(662, '✓', '#16a34a', 'Project root writable', '/var/www/example-helpdesk')}
    ${button(436, 702, 568, 'Continue', '#2563eb')}
  `);
}

function wizardDatabase() {
  return svgDoc(`
    ${browserChrome()}
    ${installerCard('Andrea Helpdesk', 'Installation Wizard')}
    ${steps(1, ['Requirements', 'Database', 'Settings', 'Install', 'Done'])}
    <text x="432" y="460" font-family="Arial, Helvetica, sans-serif" font-size="22" font-weight="700" fill="#243244">Database Connection</text>
    ${field(436, 500, 394, 'Host', 'localhost')}
    ${field(846, 500, 158, 'Port', '3306')}
    ${field(436, 592, 568, 'Database Name', 'helpdesk')}
    ${field(436, 684, 274, 'Username', 'helpdesk_user')}
    ${field(730, 684, 274, 'Password', '••••••••••••')}
    ${button(436, 748, 568, 'Test & Continue', '#2563eb')}
  `);
}

function wizardSettings() {
  return svgDoc(`
    ${browserChrome()}
    ${installerCard('Andrea Helpdesk', 'Installation Wizard')}
    ${steps(2, ['Requirements', 'Database', 'Settings', 'Install', 'Done'])}
    <text x="432" y="455" font-family="Arial, Helvetica, sans-serif" font-size="22" font-weight="700" fill="#243244">Application Settings</text>
    <text x="436" y="486" font-family="Arial, Helvetica, sans-serif" font-size="13" font-weight="700" fill="#7b8898">GENERAL</text>
    ${field(436, 510, 568, 'App URL', 'https://support.example.test', 'The public URL of this helpdesk, without a trailing slash.')}
    ${field(436, 604, 274, 'Timezone', 'Pacific/Auckland', 'PHP timezone identifier.')}
    ${field(730, 604, 274, 'Storage Path', '/home/example/andrea-helpdesk-storage', 'Absolute path outside public_html.')}
    <text x="436" y="715" font-family="Arial, Helvetica, sans-serif" font-size="13" font-weight="700" fill="#7b8898">ADMIN ACCOUNT</text>
    ${field(436, 738, 274, 'Name', 'Support Admin')}
    ${field(730, 738, 274, 'Email', 'admin@example.test')}
  `);
}

function wizardComplete() {
  return svgDoc(`
    ${browserChrome()}
    ${installerCard('Andrea Helpdesk', 'Installation Wizard')}
    ${steps(4, ['Requirements', 'Database', 'Settings', 'Install', 'Done'])}
    <text x="432" y="460" font-family="Arial, Helvetica, sans-serif" font-size="22" font-weight="700" fill="#243244">Installation Log</text>
    ${resultRow(492, 'ok', 'Created: /home/example/andrea-helpdesk-storage')}
    ${resultRow(536, 'ok', 'Created .env')}
    ${resultRow(580, 'ok', 'Database schema created')}
    ${resultRow(624, 'ok', 'Admin account created: admin@example.test')}
    ${resultRow(668, 'warning', 'vendor/ already uploaded — skipping Composer')}
    ${resultRow(712, 'ok', 'Install lock written')}
    <rect x="432" y="760" width="592" height="58" rx="14" fill="#dff4e5" stroke="#bfe8ca"/>
    <text x="464" y="795" font-family="Arial, Helvetica, sans-serif" font-size="16" font-weight="700" fill="#1c6b43">Installation complete. Delete or restrict public_html/install/ before going live.</text>
  `);
}

const files = {
  'install-cli': terminalMock(),
  'install-ftp': ftpMock(),
  'install-web-requirements': wizardRequirements(),
  'install-web-database': wizardDatabase(),
  'install-web-settings': wizardSettings(),
  'install-web-complete': wizardComplete(),
};

for (const [name, svg] of Object.entries(files)) {
  const svgPath = path.join(OUT_DIR, `${name}.svg`);
  const pngPath = path.join(OUT_DIR, `${name}.png`);
  fs.writeFileSync(svgPath, svg);
  execFileSync('convert', [svgPath, pngPath]);
  console.log(`Wrote ${path.relative(process.cwd(), pngPath)}`);
}
