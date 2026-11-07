import { type LogType } from '@pages/Logs/data/useFetchLogs'
import { type TableColumnsType } from 'antd'
import { describe, expect, it } from 'vitest'
import fitColumns, { scrollFor } from './fitColumns'

const cols: TableColumnsType<LogType> = [
  { key: 'result', width: 120 },
  { key: 'delivery', width: 150 },
  { key: 'connection', width: 160 },
  { key: 'subject' },
  { key: 'to_addr', width: 220 },
  { key: 'retry_count', width: 80 },
  { key: 'tracking', width: 120 },
  { key: 'created_at', width: 160 }
]

const keysAt = (width: number) => fitColumns(cols, width).map(column => column.key)

describe('fitColumns', () => {
  it('keeps every column on a wide table', () => {
    expect(keysAt(1400)).toHaveLength(cols.length)
  })

  it('drops Retries then To so Subject keeps its minimum in a 1183px card', () => {
    expect(keysAt(1183)).toEqual([
      'result',
      'delivery',
      'connection',
      'subject',
      'tracking',
      'created_at'
    ])
  })

  it('drops Retries first', () => {
    expect(keysAt(1300)).not.toContain('retry_count')
    expect(keysAt(1300)).toContain('to_addr')
  })

  it('drops Connection before Subject on a narrow table', () => {
    expect(keysAt(900)).toEqual(['result', 'delivery', 'subject', 'tracking', 'created_at'])
  })

  it('drops Subject last on a phone', () => {
    expect(keysAt(400)).toEqual(['result', 'delivery', 'tracking', 'created_at'])
  })

  it('keeps all columns while the width is unmeasured', () => {
    expect(keysAt(0)).toHaveLength(cols.length)
  })
})

describe('scrollFor', () => {
  it('scrolls at max-content when Subject is hidden', () => {
    expect(scrollFor(fitColumns(cols, 400))).toEqual({ x: 'max-content' })
  })

  it('reserves the Subject minimum plus fixed columns when Subject shows', () => {
    expect(scrollFor(fitColumns(cols, 1183))).toEqual({ x: 1028 })
  })
})
