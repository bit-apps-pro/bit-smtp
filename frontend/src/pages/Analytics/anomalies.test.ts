import { describe, expect, it } from 'vitest'
import { describeObservation } from './anomalies'

describe('describeObservation', () => {
  it('volume_change is informational (no status color) and directional by sign', () => {
    const up = describeObservation({
      type: 'volume_change',
      current: 120,
      prior: 100,
      percentage_change: 20
    })
    expect(up.direction).toBe('up')
    expect(up.severity).toBeNull()

    const down = describeObservation({
      type: 'volume_change',
      current: 80,
      prior: 100,
      percentage_change: -20
    })
    expect(down.direction).toBe('down')
  })

  it('failure_rate_change escalates to critical past a 10-point jump, warning below it, good when it falls', () => {
    const worse = describeObservation({
      type: 'failure_rate_change',
      current_failure_rate: 25,
      prior_failure_rate: 10,
      percentage_point_change: 15
    })
    expect(worse.severity).toBe('critical')
    expect(worse.direction).toBe('up')

    const slightlyWorse = describeObservation({
      type: 'failure_rate_change',
      current_failure_rate: 12,
      prior_failure_rate: 10,
      percentage_point_change: 2
    })
    expect(slightlyWorse.severity).toBe('warning')

    const better = describeObservation({
      type: 'failure_rate_change',
      current_failure_rate: 5,
      prior_failure_rate: 10,
      percentage_point_change: -5
    })
    expect(better.severity).toBe('good')
    expect(better.direction).toBe('down')
  })

  it('failure_rate_change and connection_failure_rate_change treat an exact zero-point change as informational, not "Improved"', () => {
    const unchanged = describeObservation({
      type: 'failure_rate_change',
      current_failure_rate: 10,
      prior_failure_rate: 10,
      percentage_point_change: 0
    })
    expect(unchanged.severity).toBeNull()
    expect(unchanged.direction).toBeNull()

    const unchangedConnection = describeObservation({
      type: 'connection_failure_rate_change',
      connection: 'ses-primary',
      current_failure_rate: 10,
      prior_failure_rate: 10,
      percentage_point_change: 0
    })
    expect(unchangedConnection.severity).toBeNull()
    expect(unchangedConnection.direction).toBeNull()
  })

  it('formats failure-rate detail percentages to one decimal place instead of raw backend floats', () => {
    const described = describeObservation({
      type: 'failure_rate_change',
      current_failure_rate: 12.3456,
      prior_failure_rate: 9.98765,
      percentage_point_change: 2.35795
    })
    expect(described.detail).toBe('12.3% now vs 10.0% prior')
  })

  it('newly_active_source / inactive_source carry the source name and a fixed direction', () => {
    const arrived = describeObservation({
      type: 'newly_active_source',
      source: 'woocommerce',
      total: 42
    })
    expect(arrived.label).toContain('woocommerce')
    expect(arrived.direction).toBe('up')

    const quiet = describeObservation({ type: 'inactive_source', source: 'wpforms', total: 7 })
    expect(quiet.label).toContain('wpforms')
    expect(quiet.direction).toBe('down')
  })

  it('weekday_distribution_shift resolves the 1-7 weekday number to a name and formats its percentages', () => {
    const monday = describeObservation({
      type: 'weekday_distribution_shift',
      weekday: 1,
      current: 10,
      prior: 5,
      current_percentage: 20.049,
      prior_percentage: 10.011,
      percentage_point_change: 10
    })
    expect(monday.label).toContain('Monday')
    expect(monday.detail).toBe('20.0% now vs 10.0% prior')
  })

  it('hourly_distribution_shift formats its percentages to one decimal place instead of raw backend floats', () => {
    const described = describeObservation({
      type: 'hourly_distribution_shift',
      hour: 9,
      current: 10,
      prior: 5,
      current_percentage: 20.049,
      prior_percentage: 10.011,
      percentage_point_change: 10
    })
    expect(described.detail).toBe('20.0% now vs 10.0% prior')
  })

  it('connection_failure_rate_change names the connection and applies the same severity rule', () => {
    const described = describeObservation({
      type: 'connection_failure_rate_change',
      connection: 'ses-primary',
      current_failure_rate: 30,
      prior_failure_rate: 5,
      percentage_point_change: 25
    })
    expect(described.label).toContain('ses-primary')
    expect(described.severity).toBe('critical')
  })

  it('falls back to an informational, null-severity description for an observation type the union does not list', () => {
    // Simulates a backend contract drift (a new/unrecognized observation type) reaching the UI.
    const described = describeObservation({ type: 'unknown_future_observation' } as never)
    expect(described.label).toBe('Unrecognized observation')
    expect(described.severity).toBeNull()
    expect(described.direction).toBe('up')
  })
})
