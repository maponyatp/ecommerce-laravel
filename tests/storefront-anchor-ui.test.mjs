import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const blade = readFileSync(new URL('../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
const code = blade.split('// Enhanced smooth scrolling')[1].split('// Enhanced image lazy loading')[0];

function click(href, existingId) {
    let handler, prevented = false, scrolled = false;
    const target = { scrollIntoView() { scrolled = true; } };
    const anchor = { getAttribute() { return href; }, addEventListener(_, callback) { handler = callback; } };
    vm.runInNewContext(code, { document: {
        querySelectorAll() { return [anchor]; },
        querySelector(selector) {
            if (selector === '#' || selector.includes('%')) throw new SyntaxError('Invalid selector');
            return selector === '#'+existingId ? target : null;
        },
        getElementById(id) { return id === existingId ? target : null; },
    } });
    handler.call(anchor, { preventDefault() { prevented = true; } });
    return { prevented, scrolled };
}

test('placeholder hash links never throw selector errors', () => {
    assert.deepEqual(click('#'), { prevented: false, scrolled: false });
});

test('encoded IDs are resolved as literal element IDs', () => {
    assert.deepEqual(click('#flower%3Aoffer', 'flower:offer'), { prevented: true, scrolled: true });
});

test('invalid percent encoding and absent targets preserve native navigation', () => {
    assert.deepEqual(click('#%ZZ'), { prevented: false, scrolled: false });
    assert.deepEqual(click('#missing'), { prevented: false, scrolled: false });
});

test('valid homepage section links still scroll smoothly', () => {
    assert.deepEqual(click('#featured-categories', 'featured-categories'), { prevented: true, scrolled: true });
});
