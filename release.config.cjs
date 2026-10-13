const { resolve } = require('node:path');
const runtime = name => require.resolve(name, { paths: [resolve(__dirname, '.github/release-tools')] });
module.exports = {
  branches: ['main', { name: 'beta', prerelease: true, channel: 'beta' }, { name: 'alpha', prerelease: true, channel: 'alpha' }, { name: 'dev', prerelease: true, channel: 'dev' }],
  tagFormat: 'v${version}',
  plugins: [
    './.github/release-tools/analyze-commits.mjs',
    [runtime('@semantic-release/release-notes-generator'), { parserOpts: { headerPattern: /^(\w*)(?:\((.*)\))?!?: (.*)$/, breakingHeaderPattern: /^(\w*)(?:\((.*)\))?!: (.*)$/, headerCorrespondence: ['type', 'scope', 'subject'] } }],
    [runtime('@semantic-release/github'), { successComment: false, failComment: false, releasedLabels: false }],
  ],
};
