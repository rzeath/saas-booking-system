import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { expect, test, vi } from 'vitest'

import { Modal } from '@/components/ui/modal'

test('labels the dialog, traps focus, restores focus, and closes with Escape', () => {
  const onClose = vi.fn()
  function Harness() {
    const [open, setOpen] = useState(false)
    return <><button type="button" onClick={() => setOpen(true)}>Open editor</button>{open ? <Modal title="Edit customer" description="Update customer details." onClose={() => { onClose(); setOpen(false) }}><button type="button">Save customer</button></Modal> : null}</>
  }
  render(<Harness />)

  const opener = screen.getByRole('button', { name: 'Open editor' })
  opener.focus()
  fireEvent.click(opener)
  const dialog = screen.getByRole('dialog', { name: 'Edit customer' })
  expect(dialog).toHaveAccessibleDescription('Update customer details.')
  const close = screen.getByRole('button', { name: 'Close dialog' })
  const save = screen.getByRole('button', { name: 'Save customer' })

  close.focus()
  fireEvent.keyDown(document, { key: 'Tab', shiftKey: true })
  expect(save).toHaveFocus()
  fireEvent.keyDown(document, { key: 'Tab' })
  expect(close).toHaveFocus()
  fireEvent.keyDown(document, { key: 'Escape' })
  expect(onClose).toHaveBeenCalledOnce()
  expect(opener).toHaveFocus()
})
