import { renderWithProviders } from '@config/test-utils'
import { useExportPreferences, useImportPreferences } from '@pages/Settings/data/usePreferences'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import PrivacyData from './PrivacyData'

vi.mock('@common/helpers/request', () => ({
  default: vi.fn().mockResolvedValue({ status: 'success', data: { answered: true, allowed: false } })
}))
vi.mock('@pages/Settings/data/usePreferences', () => ({
  useExportPreferences: vi.fn(),
  useImportPreferences: vi.fn()
}))

/** Render PrivacyData inside a Form (its fields bind to a Form context) and the app providers. */
function renderPanel(initialValues = { uninstall_purge: true, tracking_enabled: false }) {
  return renderWithProviders(
    <Form initialValues={initialValues}>
      <PrivacyData />
    </Form>
  )
}

describe('PrivacyData', () => {
  beforeEach(() => {
    ;(useExportPreferences as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
    ;(useImportPreferences as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
  })

  it('keeps the tracking hint short and moves the detail into a "What gets tracked?" popover', async () => {
    renderPanel()

    expect(screen.getByText('Track opens and clicks')).toBeInTheDocument()
    expect(screen.getByText(/Needs email logs turned on/)).toBeInTheDocument()
    expect(screen.queryByText(/tiny invisible image/)).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Help: Track opens and clicks' }))

    expect(await screen.findByText('What gets tracked?')).toBeInTheDocument()
    expect(screen.getByText(/tiny invisible image/)).toBeInTheDocument()
  })

  it('warns that deleting the plugin removes everything for good', () => {
    renderPanel()

    expect(screen.getByText(/This can’t be undone/)).toBeInTheDocument()
  })

  it('asks before an import replaces the settings', async () => {
    renderPanel()
    const input = document.querySelector('input[type="file"]') as HTMLInputElement
    const openPicker = vi.spyOn(input, 'click')

    await userEvent.click(screen.getByRole('button', { name: /import settings/i }))
    expect(openPicker).not.toHaveBeenCalled()
    expect(await screen.findByText('Replace your settings?')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Choose file' }))

    expect(openPicker).toHaveBeenCalledTimes(1)
  })
})
