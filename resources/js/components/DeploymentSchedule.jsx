import { parseDateTime, today, toCalendarDate, toCalendarDateTime, toTime } from '@internationalized/date';
import { useEffect, useState } from 'react';
import {
    Button, Calendar, CalendarCell, CalendarGrid, CalendarGridBody,
    CalendarGridHeader, CalendarHeaderCell, DateInput, DateSegment,
    FieldError, Heading, I18nProvider, Label, TimeField,
} from 'react-aria-components';

const navigationButton = 'grid size-10 cursor-pointer place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-prosof-action disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700';

export default function DeploymentSchedule({ error, onChange, timezone, value }) {
    const [date, setDate] = useState(() => value ? toCalendarDate(parseDateTime(value)) : null);
    const [time, setTime] = useState(() => value ? toTime(parseDateTime(value)) : null);
    const minimumDate = today(timezone);
    const selectedDate = date && date.compare(minimumDate) >= 0 ? date : null;

    useEffect(() => {
        if (date && !selectedDate) {
            setDate(null);
            onChange('');
        }
    }, [date, onChange, selectedDate]);

    function changeDate(nextDate) {
        setDate(nextDate);
        onChange(nextDate && time ? toCalendarDateTime(nextDate, time).toString().slice(0, 16) : '');
    }

    function changeTime(nextTime) {
        setTime(nextTime);
        onChange(selectedDate && nextTime ? toCalendarDateTime(selectedDate, nextTime).toString().slice(0, 16) : '');
    }

    return (
        <I18nProvider locale="es-CO">
            <fieldset className="min-w-0 space-y-3">
                <legend className="mb-2 text-sm font-medium text-slate-700 dark:text-slate-200">Fecha y hora del despliegue</legend>
                <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 sm:p-4 dark:border-slate-700 dark:bg-slate-950">
                    <Calendar aria-label="Fecha del despliegue" className="w-full text-slate-700 dark:text-slate-200" minValue={minimumDate} onChange={changeDate} value={selectedDate}>
                        <header className="mb-3 flex items-center justify-between gap-2">
                            <Button aria-label="Mes anterior" className={navigationButton} slot="previous">
                                <span aria-hidden="true">&lsaquo;</span>
                            </Button>
                            <Heading className="text-center text-sm font-bold capitalize" />
                            <Button aria-label="Mes siguiente" className={navigationButton} slot="next">
                                <span aria-hidden="true">&rsaquo;</span>
                            </Button>
                        </header>
                        <CalendarGrid className="w-full table-fixed border-separate border-spacing-0.5" weekdayStyle="short">
                            <CalendarGridHeader>
                                {(day) => <CalendarHeaderCell className="pb-2 text-xs font-medium text-slate-500 dark:text-slate-400">{day}</CalendarHeaderCell>}
                            </CalendarGridHeader>
                            <CalendarGridBody>
                                {(day) => (
                                    <CalendarCell
                                        className="flex min-h-10 cursor-pointer items-center justify-center rounded-lg text-sm outline-none transition hover:bg-slate-200 data-[disabled]:cursor-not-allowed data-[disabled]:opacity-40 data-[disabled]:hover:bg-transparent data-[outside-month]:text-slate-400 data-[selected]:bg-prosof-action data-[selected]:font-bold data-[selected]:text-white data-[focus-visible]:ring-2 data-[focus-visible]:ring-prosof-lime dark:hover:bg-slate-800 dark:data-[disabled]:hover:bg-transparent dark:data-[selected]:bg-prosof-action dark:data-[outside-month]:text-slate-600"
                                        date={day}
                                    />
                                )}
                            </CalendarGridBody>
                        </CalendarGrid>
                    </Calendar>
                    <TimeField
                        className="mt-4 flex flex-col gap-2 border-t border-slate-200 pt-3 dark:border-slate-700"
                        granularity="minute"
                        hourCycle={12}
                        isRequired
                        onChange={changeTime}
                        value={time}
                    >
                        <Label className="text-sm font-medium text-slate-700 dark:text-slate-200">Hora (AM/PM)</Label>
                        <DateInput className="flex min-h-12 items-center justify-center gap-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xl font-semibold text-slate-900 focus-within:ring-2 focus-within:ring-prosof-lime/40 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                            {(segment) => (
                                <DateSegment className="rounded px-1 py-1 tabular-nums outline-none data-[placeholder]:text-slate-400 focus:bg-prosof-action focus:text-white" segment={segment} />
                            )}
                        </DateInput>
                        <FieldError className="text-sm text-red-700 dark:text-red-300" />
                    </TimeField>
                </div>
                <p className="text-xs text-slate-600 dark:text-slate-300" role="status">
                    {selectedDate && time ? (
                        <>Programado para el {new Intl.DateTimeFormat('es-CO', { dateStyle: 'long', timeZone: 'UTC' }).format(selectedDate.toDate('UTC'))} a las {new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'UTC' }).format(toCalendarDateTime(selectedDate, time).toDate('UTC'))} · {timezone}</>
                    ) : 'Selecciona un día y completa la hora. Se utilizará la zona horaria indicada.'}
                </p>
                {error && <p className="text-sm text-red-700 dark:text-red-300" role="alert">{error}</p>}
            </fieldset>
        </I18nProvider>
    );
}
