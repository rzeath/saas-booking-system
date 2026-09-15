import { BriefcaseBusiness, PackageOpen, Tags, UserRound } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'

import { cn } from '@/lib/utils'
import { EventTypesRoute } from '@/routes/event-types'
import { PackagesRoute } from '@/routes/packages'
import { ServicesRoute } from '@/routes/services'
import { StaffRoute } from '@/routes/staff'

const tabs = [
  { id: 'event-types', label: 'Event Types', icon: Tags },
  { id: 'services', label: 'Services', icon: BriefcaseBusiness },
  { id: 'packages', label: 'Packages', icon: PackageOpen },
  { id: 'staff', label: 'Staff', icon: UserRound },
] as const

type MasterDataTab = (typeof tabs)[number]['id']

function isMasterDataTab(value: string | null): value is MasterDataTab {
  return tabs.some((tab) => tab.id === value)
}

export function MasterDataRoute() {
  const [searchParams, setSearchParams] = useSearchParams()
  const requestedTab = searchParams.get('tab')
  const activeTab: MasterDataTab = isMasterDataTab(requestedTab) ? requestedTab : 'event-types'

  const selectTab = (tab: MasterDataTab) => {
    const next = new URLSearchParams(searchParams)
    next.set('tab', tab)
    setSearchParams(next)
  }

  return (
    <section>
      <header>
        <p className="text-xs font-bold uppercase tracking-[0.14em] text-primary">Configuration</p>
        <h1 className="mt-2 text-3xl font-semibold text-foreground">Master Data</h1>
        <p className="mt-2 text-sm text-muted">Manage the configuration used by bookings and pricing.</p>
      </header>

      <div className="mt-7 border-b border-border">
        <div role="tablist" aria-label="Master Data" className="flex gap-1 overflow-x-auto">
          {tabs.map((tab) => {
            const Icon = tab.icon
            const selected = activeTab === tab.id

            return (
              <button
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={selected}
                aria-controls={`master-data-panel-${tab.id}`}
                id={`master-data-tab-${tab.id}`}
                onClick={() => selectTab(tab.id)}
                className={cn(
                  'inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-4 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                  selected ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-foreground',
                )}
              >
                <Icon className="size-4" aria-hidden="true" />
                {tab.label}
              </button>
            )
          })}
        </div>
      </div>

      <div
        role="tabpanel"
        id={`master-data-panel-${activeTab}`}
        aria-labelledby={`master-data-tab-${activeTab}`}
        className="pt-6"
      >
        {activeTab === 'event-types' ? <EventTypesRoute embedded /> : null}
        {activeTab === 'services' ? <ServicesRoute embedded /> : null}
        {activeTab === 'packages' ? <PackagesRoute embedded /> : null}
        {activeTab === 'staff' ? <StaffRoute embedded /> : null}
      </div>
    </section>
  )
}
