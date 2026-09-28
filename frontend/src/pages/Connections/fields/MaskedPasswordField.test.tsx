import { type FieldMeta, MASK_SENTINEL } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Button, Form } from 'antd'
import { describe, expect, it, vi } from 'vitest'
import MaskedPasswordField from './MaskedPasswordField'

const passwordField: FieldMeta = {
  key: 'password',
  label: 'Password',
  type: 'password',
  secret: true,
  required: false,
  placeholder: '',
  default: '',
  options: [],
  dependsOn: null
}

describe('MaskedPasswordField', () => {
  it('keeps the sentinel until edited, then submits the typed value', async () => {
    const onFinish = vi.fn()
    render(
      <Form onFinish={onFinish} initialValues={{ password: MASK_SENTINEL }}>
        <MaskedPasswordField field={passwordField} />
        <button type="submit">save</button>
      </Form>
    )

    await userEvent.click(screen.getByText('save'))
    expect(onFinish).toHaveBeenCalledWith({ password: MASK_SENTINEL })

    await userEvent.clear(screen.getByLabelText('Password'))
    await userEvent.type(screen.getByLabelText('Password'), 'newsecret')
    await userEvent.click(screen.getByText('save'))
    expect(onFinish).toHaveBeenLastCalledWith({ password: 'newsecret' })
  })

  it('hides the eye toggle for a saved (masked) secret', () => {
    render(
      <Form initialValues={{ password: MASK_SENTINEL }}>
        <MaskedPasswordField field={passwordField} />
      </Form>
    )

    expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password')
    expect(screen.queryByRole('img', { name: 'eye-invisible' })).not.toBeInTheDocument()
  })

  it('clears the sentinel and restores the eye toggle when the masked field is focused', async () => {
    render(
      <Form initialValues={{ password: MASK_SENTINEL }}>
        <MaskedPasswordField field={passwordField} />
      </Form>
    )

    await userEvent.click(screen.getByLabelText('Password'))

    expect(screen.getByLabelText('Password')).toHaveValue('')
    expect(await screen.findByRole('img', { name: 'eye-invisible' })).toBeInTheDocument()
  })

  it('restores the sentinel if a masked field is focused then left untouched', async () => {
    render(
      <Form initialValues={{ password: MASK_SENTINEL }}>
        <MaskedPasswordField field={passwordField} />
        <button type="button">elsewhere</button>
      </Form>
    )

    await userEvent.click(screen.getByLabelText('Password'))
    await userEvent.click(screen.getByText('elsewhere'))

    expect(screen.getByLabelText('Password')).toHaveValue(MASK_SENTINEL)
    expect(screen.queryByRole('img', { name: 'eye-invisible' })).not.toBeInTheDocument()
  })

  it('never injects the sentinel into a field that never had a stored secret', async () => {
    render(
      <Form initialValues={{ password: '' }}>
        <MaskedPasswordField field={passwordField} />
        <button type="button">elsewhere</button>
      </Form>
    )

    await userEvent.click(screen.getByLabelText('Password'))
    await userEvent.click(screen.getByText('elsewhere'))

    expect(screen.getByLabelText('Password')).toHaveValue('')
  })

  it('treats a secret stored after mount (e.g. a post-save reseed) as stored', async () => {
    render(
      <Form initialValues={{ password: '' }}>
        <MaskedPasswordField field={passwordField} />
        <ReseedButton value={MASK_SENTINEL} />
        <button type="button">elsewhere</button>
      </Form>
    )

    await userEvent.click(screen.getByText('reseed'))
    await userEvent.click(screen.getByLabelText('Password'))
    await userEvent.click(screen.getByText('elsewhere'))

    expect(screen.getByLabelText('Password')).toHaveValue(MASK_SENTINEL)
  })

  it('stops restoring the sentinel once the stored secret is reseeded as removed', async () => {
    render(
      <Form initialValues={{ password: MASK_SENTINEL }}>
        <MaskedPasswordField field={passwordField} />
        <ReseedButton value="" />
        <button type="button">elsewhere</button>
      </Form>
    )

    await userEvent.click(screen.getByText('reseed'))
    await userEvent.click(screen.getByLabelText('Password'))
    await userEvent.click(screen.getByText('elsewhere'))

    expect(screen.getByLabelText('Password')).toHaveValue('')
  })

  it('keeps restoring the stored secret after the user typed then cleared a replacement', async () => {
    render(
      <Form initialValues={{ password: MASK_SENTINEL }}>
        <MaskedPasswordField field={passwordField} />
        <button type="button">elsewhere</button>
      </Form>
    )

    await userEvent.type(screen.getByLabelText('Password'), 'draft')
    await userEvent.click(screen.getByText('elsewhere'))
    await userEvent.clear(screen.getByLabelText('Password'))
    await userEvent.click(screen.getByText('elsewhere'))

    expect(screen.getByLabelText('Password')).toHaveValue(MASK_SENTINEL)
  })
})

/** Simulates an out-of-band reseed (as after a save) by setting the password field from outside the input. */
function ReseedButton({ value }: { value: string }) {
  const form = Form.useFormInstance()

  return <Button onClick={() => form.setFieldValue('password', value)}>reseed</Button>
}
