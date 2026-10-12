'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const release = require('./release.cjs');
const sha = 'a'.repeat(40);
const inherited = ['0.2', 'v.1.1', 'v0.1', 'v0.2', 'v1.0', 'v1.1', 'v1.2', 'v1.3',
  'v1.4', 'v1.5', 'v1.5.1', 'v1.6', 'v1.7', 'v1.8'];

function harness(options = {}) {
  const calls = [];
  let branchReads = 0;
  const context = {
    eventName: 'push', ref: 'refs/heads/master', sha,
    repo: {owner: 'lacodix', repo: 'laravel-notification-subscriptions'},
    ...options.context,
  };
  const core = {info: message => calls.push(['info', message])};
  const notFound = () => { throw Object.assign(new Error('not found'), {status: 404}); };
  const github = {
    paginate: async (method, params) => {
      assert.equal(method, github.rest.repos.listTags);
      assert.equal(params.per_page, 100);
      return (options.tags || inherited).map(name => ({name}));
    },
    rest: {
      repos: {
        getBranch: async () => ({data: {commit: {sha: (++branchReads >= (options.moveAt || Infinity)) ? 'b'.repeat(40) : sha}}}),
        getLatestRelease: async () => {
          if (options.latestError) throw Object.assign(new Error('API error'), {status: options.latestError});
          return options.latest ? {data: {tag_name: options.latest}} : notFound();
        },
        listTags: () => { throw new Error('Use pagination'); },
        createRelease: async params => {
          if (options.releaseError) throw new Error('release failed');
          calls.push(['release', params]);
        },
      },
      git: {
        getRef: async params => {
          calls.push(['check', params]);
          if (options.tagError) throw Object.assign(new Error('API error'), {status: options.tagError});
          return options.collision ? {data: {}} : notFound();
        },
        createRef: async params => {
          if (options.tagRace) throw Object.assign(new Error('ref already exists'), {status: 422});
          calls.push(['tag', params]);
        },
      },
    },
  };
  return {calls, run: plannedTag => release({github, context, core, plannedTag})};
}

for (const [plan, expected] of [['v0.1.0', 'v2.0.0'], ['v0.0.1', 'v2.0.0'], ['v1.0.0', 'v2.0.0']]) {
  test(`bootstrap ${plan} becomes ${expected} above inherited tags`, async () => {
    const h = harness();
    await h.run(plan);
    const payload = h.calls.find(([kind]) => kind === 'release')[1];
    assert.equal(payload.tag_name, expected);
    assert.equal(payload.target_commitish, sha);
    assert.equal(payload.generate_release_notes, true);
    assert.equal(payload.draft, false);
    assert.equal(payload.prerelease, false);
    assert.deepEqual(h.calls.filter(([kind]) => ['check', 'tag', 'release'].includes(kind)).map(([kind]) => kind),
      ['check', 'tag', 'release']);
    assert.equal(h.calls.find(([kind]) => kind === 'tag')[1].sha, sha);
  });
}

test('highest tag wins independently of API order', async () => {
  const h = harness({tags: ['v2.3.9', ...inherited]});
  await h.run('v0.1.0');
  assert.equal(h.calls.find(([kind]) => kind === 'release')[1].tag_name, 'v3.0.0');
});

test('normal releases retain the action plan', async () => {
  const h = harness({latest: 'v1.9.0', tags: [...inherited, 'v1.9.0']});
  await h.run('v1.9.1');
  assert.equal(h.calls.find(([kind]) => kind === 'release')[1].tag_name, 'v1.9.1');
});

test('after the namespace major, a normal minor plan remains minor', async () => {
  const h = harness({latest: 'v2.0.0', tags: [...inherited, 'v2.0.0']});
  await h.run('v2.1.0');
  assert.equal(h.calls.find(([kind]) => kind === 'release')[1].tag_name, 'v2.1.0');
});

test('norelease yields no writes', async () => {
  const h = harness();
  await h.run('');
  assert.deepEqual(h.calls, []);
});

for (const moveAt of [1, 2]) {
  test(`a moved master at check ${moveAt} skips publication`, async () => {
    const h = harness({moveAt});
    await h.run('v0.1.0');
    assert.equal(h.calls.filter(([kind]) => ['tag', 'release'].includes(kind)).length, 0);
  });
}

for (const [name, options, plan, pattern] of [
  ['existing tag', {collision: true}, 'v0.1.0', /already exists/],
  ['tag API failure', {tagError: 403}, 'v0.1.0', /API error/],
  ['release API failure', {latestError: 500}, 'v0.1.0', /API error/],
  ['unknown bootstrap plan', {}, 'v0.2.0', /Unexpected first-release/],
  ['no stable tags', {tags: ['typo']}, 'v0.1.0', /owner bootstrap/],
  ['malformed plan', {}, 'v1.9', /stable vMAJOR/],
  ['historical collision', {latest: 'v1.7.0'}, 'v1.8.0', /inherited tags/],
  ['plan behind latest', {latest: 'v2.0.0'}, 'v1.9.0', /latest stable/],
  ['unsupported release baseline', {latest: 'preview'}, 'v1.9.0', /latest stable/],
  ['pull request', {context: {eventName: 'pull_request'}}, 'v0.1.0', /validated push/],
  ['feature branch', {context: {ref: 'refs/heads/feature'}}, 'v0.1.0', /validated push/],
  ['other fork', {context: {repo: {owner: 'someone', repo: 'laravel-notification-subscriptions'}}}, 'v0.1.0', /validated push/],
  ['nonexact SHA', {context: {sha: 'master'}}, 'v0.1.0', /exact commit SHA/],
  ['tag creation race', {tagRace: true}, 'v0.1.0', /ref already exists/],
]) {
  test(`${name} fails closed`, async () => {
    const h = harness(options);
    await assert.rejects(h.run(plan), pattern);
    assert.equal(h.calls.filter(([kind]) => ['tag', 'release'].includes(kind)).length, 0);
  });
}

test('release creation failure leaves only the exact tag for owner recovery', async () => {
  const h = harness({releaseError: true});
  await assert.rejects(h.run('v0.1.0'), /release failed/);
  assert.equal(h.calls.filter(([kind]) => kind === 'tag').length, 1);
  assert.equal(h.calls.filter(([kind]) => kind === 'release').length, 0);
});
