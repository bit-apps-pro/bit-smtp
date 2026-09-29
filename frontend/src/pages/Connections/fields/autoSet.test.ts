import { type FieldMeta } from '@pages/Connections/types'
import { describe, expect, it } from 'vitest'
import autoSetPatch from './autoSet'

const encryption: FieldMeta = {
  key: 'encryption',
  label: 'Security',
  type: 'select',
  required: true,
  secret: false,
  placeholder: '',
  default: 'tls',
  options: [],
  dependsOn: null,
  autoSet: { field: 'port', values: { tls: 587, ssl: 465, none: 25 } }
}

describe('autoSetPatch', () => {
  it('sets the target to the mapped value while it still holds a mapped default', () => {
    expect(autoSetPatch({ encryption: 'ssl' }, { encryption: 'ssl', port: 587 }, [encryption])).toEqual({
      port: 465
    })
  })

  it('fills an empty target', () => {
    expect(
      autoSetPatch({ encryption: 'tls' }, { encryption: 'tls', port: undefined }, [encryption])
    ).toEqual({
      port: 587
    })
  })

  it('leaves a customised target alone', () => {
    expect(autoSetPatch({ encryption: 'ssl' }, { encryption: 'ssl', port: 2525 }, [encryption])).toEqual(
      {}
    )
  })

  it('ignores changes to fields without autoSet', () => {
    expect(autoSetPatch({ host: 'smtp.x' }, { host: 'smtp.x', port: 587 }, [encryption])).toEqual({})
  })
})
