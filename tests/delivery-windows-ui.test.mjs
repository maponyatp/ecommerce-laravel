import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const code = readFileSync(new URL('../resources/views/checkout/delivery-windows-script.blade.php', import.meta.url), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];

function setup(initial) {
    let ready, selected = initial;
    const methods = ['1', '2', '3'].map(value => ({value, addEventListener(event, handler) {this.change = handler;}}));
    const panels = ['1', '2'].map(id => ({dataset: {deliveryWindowMethod: id}, hidden: true,
        select: {disabled: true, required: false, value: ''}, querySelector() {return this.select;}}));
    const form = {
        querySelector() {return selected ? {value: selected} : null;},
        querySelectorAll(selector) {return selector === '[data-delivery-window-method]' ? panels : methods;},
    };
    vm.runInNewContext(code, {document: {getElementById() {return form;}, addEventListener(event, handler) {ready = handler;}}});
    ready();
    return {panels, change(value) {selected = value; methods.find(method => method.value === value).change();}};
}

test('only the selected delivery method submits a required window', () => {
    const page = setup('1');
    assert.equal(page.panels[0].hidden, false);
    assert.equal(page.panels[0].select.disabled, false);
    assert.equal(page.panels[0].select.required, true);
    assert.equal(page.panels[1].hidden, true);
    assert.equal(page.panels[1].select.disabled, true);
    page.change('2');
    assert.equal(page.panels[0].select.disabled, true);
    assert.equal(page.panels[0].select.required, false);
    assert.equal(page.panels[1].select.disabled, false);
    assert.equal(page.panels[1].select.required, true);
});

test('switching to an unscheduled method omits all delivery window fields', () => {
    const page = setup('1');
    page.panels[0].select.value = '77';
    page.change('3');
    assert.ok(page.panels.every(panel => panel.hidden && panel.select.disabled && !panel.select.required));
});

test('an unselected method never submits an invisible required window', () => {
    const page = setup(null);
    assert.ok(page.panels.every(panel => panel.hidden && panel.select.disabled && !panel.select.required));
});
