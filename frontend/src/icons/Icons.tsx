import { type JSX } from 'react'
import EditIcon from './EditIcon'
import cls from './Icons.module.css'

export default function Icons(): JSX.Element {
  const allIcons = [<EditIcon size={20} stroke={2} />]
  return (
    <div className={cls.iconsSection}>
      {allIcons.map(v => (
        <div className={cls.icon}>{v}</div>
      ))}
    </div>
  )
}
