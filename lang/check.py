"""Every t('...') key used in the given files must be in every lang/<code>/*.json catalogue (US-122).
Usage: python3 lang/check.py [--all] <file> ...   (exit 1 when a key is missing or a catalogue is not valid JSON)
Day to day only pt_BR is checked (owner 2026-10-29: English is the key, the other languages are translated at a hard
release); --all checks every catalogue, as a hard release does (docs/RELEASING.md)."""
import glob, json, os, re, sys
base = os.path.dirname(os.path.abspath(__file__))
cats = {}
conflicts = 0
LANGS = sorted(d for d in os.listdir(base) if os.path.isdir(os.path.join(base, d)) and not d.startswith('_'))
for lang in LANGS:
    cats[lang] = {}
    for f in sorted(glob.glob(os.path.join(base, lang, '*.json'))):
        for k, v in json.load(open(f, encoding='utf-8')).items():
            # the same English text must have one translation: the catalogues are merged, the last file would win
            if k in cats[lang] and cats[lang][k] != v:
                print(f'[{lang}] two translations of: {k[:70]} ({os.path.basename(f)})'); conflicts += 1
            cats[lang][k] = v
check_all = '--all' in sys.argv
files = [a for a in sys.argv[1:] if a != '--all']
pat = re.compile(r"""\bt\(\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")""")
missing = 0
for path in files:
    src = open(path, encoding='utf-8').read()
    for m in pat.finditer(src):
        raw = m.group(1) if m.group(1) is not None else m.group(2)
        q = "'" if m.group(1) is not None else '"'
        key = raw.replace('\\' + q, q).replace('\\\\', '\\')
        if q == '"' and ('$' in key):
            print(f'{path}: variable inside a double-quoted key: {key[:60]}'); missing += 1; continue
        for lang, c in cats.items():
            if not check_all and lang != 'pt_BR':
                continue
            if key not in c:
                print(f'{path}: [{lang}] missing: {key[:90]}'); missing += 1
print('missing', missing, 'conflicts', conflicts)
sys.exit(1 if missing or conflicts else 0)
