import assert from 'node:assert/strict';
import { test } from 'node:test';

import { buildScenes } from './workflow-model.ts';

const defaults = {
    scenario: 'vendor',
    hasTeam: true,
    retry: false,
    immediateSeverities: ['Critical', 'High'],
    collectionFrequency: 'Mỗi 15 phút',
    digestTime: '08:00',
    digestDays: 'mỗi ngày',
    timezone: 'GMT+7',
    noticeRelevantDays: 14,
};
const translate = (key) => key;

test('eligible vendor news reaches admin before team matching, even without a matching team', () => {
    const scenes = buildScenes({ ...defaults, hasTeam: false }, translate);
    const admin = scenes.findIndex((scene) => scene.path === 'news-admin');
    const match = scenes.findIndex((scene) => scene.node === 'assess');

    assert.ok(admin < match);
    assert.equal(scenes[admin].admin, 'sent');
    assert.equal(scenes.at(-1)?.admin, 'sent');
    assert.equal(scenes.at(-1)?.team, 'skipped');
    assert.ok(!scenes.some((scene) => scene.packet === 'T01' || scene.node === 'team'));
});

test('a failed and retried team webhook preserves the delivered admin notification', () => {
    const scenes = buildScenes({ ...defaults, retry: true }, translate);
    const failed = scenes.find((scene) => scene.team === 'failed');
    const retry = scenes.find((scene) => scene.team === 'retry');

    assert.equal(failed?.admin, 'sent');
    assert.equal(retry?.admin, 'sent');
    assert.equal(scenes.at(-1)?.team, 'sent');
});

test('package notifications use the digest route when no severity is configured for immediate delivery', () => {
    const scenes = buildScenes({ ...defaults, scenario: 'package', immediateSeverities: [] }, translate);

    assert.ok(!scenes.some((scene) => scene.node === 'immediate'));
    assert.equal(scenes.find((scene) => scene.node === 'digest')?.team, 'waiting');
    assert.equal(scenes.at(-1)?.packet, 'D01');
    assert.ok(scenes.every((scene) => scene.admin === 'inactive'));
});

test('package notifications can use immediate delivery when configured', () => {
    const scenes = buildScenes({ ...defaults, scenario: 'package' }, translate);

    assert.ok(scenes.some((scene) => scene.node === 'immediate'));
    assert.equal(scenes.at(-1)?.packet, 'T01');
});

test('daily digest groups content into D01 before delivery to the team', () => {
    const scenes = buildScenes({ ...defaults, scenario: 'digest' }, translate);

    assert.equal(scenes.find((scene) => scene.path === 'digest-send')?.packet, 'D01');
    assert.equal(scenes.at(-1)?.packet, 'D01');
    assert.equal(scenes.at(-1)?.team, 'sent');
});

test('source failure sends an operational notification to admin without a team notification', () => {
    const scenes = buildScenes({ ...defaults, scenario: 'failure' }, translate);

    assert.equal(scenes.at(-1)?.path, 'source-failed');
    assert.equal(scenes.at(-1)?.admin, 'sent');
    assert.ok(scenes.every((scene) => scene.team === 'inactive'));
    assert.ok(!scenes.some((scene) => scene.node === 'classify' || scene.node === 'team'));
});

test('KEV retains its immediate notification route independently of the ordinary severity setting', () => {
    const scenes = buildScenes({ ...defaults, scenario: 'kev', immediateSeverities: [] }, translate);

    assert.ok(scenes.some((scene) => scene.node === 'immediate'));
    assert.equal(scenes.at(-1)?.team, 'sent');
});
