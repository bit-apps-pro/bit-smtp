import { describe, expect, it } from 'vitest'
import { defaultChannelName, newChannelId } from './notificationChannels.helpers'

describe('newChannelId', () => {
  it('generates a ch_-prefixed 12-hex-digit id', () => {
    expect(newChannelId()).toMatch(/^ch_[0-9a-f]{12}$/)
  })

  it('generates a distinct id on each call', () => {
    expect(newChannelId()).not.toBe(newChannelId())
  })
})

describe('defaultChannelName', () => {
  it.each([
    ['no sibling exists', [], 'Slack'],
    ['the bare label is taken', ['Slack'], 'Slack 2'],
    ['the first suffixes are taken', ['Slack', 'Slack 2'], 'Slack 3'],
    ['a gap exists before a higher suffix', ['Slack', 'Slack 3'], 'Slack 2'],
    ['only a suffixed name is taken', ['Slack 2'], 'Slack'],
    ['siblings were renamed', ['Ops alerts'], 'Slack']
  ])('picks the first free name when %s', (_case, names, expected) => {
    expect(
      defaultChannelName(
        'Slack',
        names.map(name => ({ name }))
      )
    ).toBe(expected)
  })
})
