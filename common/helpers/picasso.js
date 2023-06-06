import countBy from 'lodash/countBy'
import minBy from 'lodash/minBy'
import maxBy from 'lodash/maxBy'
import sortBy from 'lodash/sortBy'

/**
 * Splits a set of days into pages, such that all pages contain a similar number of days.
 *
 * @param days {array} set of days to split into pages
 * @param maxDaysPerPage {number} maximum number of days to put on one page
 * @returns {array} list of pages, each containing a list of the days on the page
 */
export function splitDaysIntoPages(days, maxDaysPerPage) {
  const numberOfDays = days.length
  const numberOfPages = Math.ceil(numberOfDays / maxDaysPerPage)
  const daysPerPage = Math.floor(numberOfDays / numberOfPages)
  const numLargerPages = numberOfDays % numberOfPages
  let nextUnassignedDayIndex = 0
  if (isNaN(numberOfPages)) return []

  return [...Array(numberOfPages).keys()].map((i) => {
    const isLargerPage = i < numLargerPages
    const numDaysOnCurrentPage = daysPerPage + (isLargerPage ? 1 : 0)
    const firstDayIndex = nextUnassignedDayIndex
    nextUnassignedDayIndex = firstDayIndex + numDaysOnCurrentPage

    return days.filter((day, index) => {
      return index >= firstDayIndex && index < nextUnassignedDayIndex
    })
  })
}

/**
 * Finds the largest consecutive time period during the night, in which no schedule entries start or end.
 * This time period can be treated as the common "bedtime" in the camp, during which
 * the people in the camp are not active. This time period can be safely hidden on the picasso.
 *
 * @param scheduleEntries set of schedule entries to consider
 * @param dayjs a dayjs helper object, needed to do time calculations
 * @param firstDay string description of the first day displayed. Is used to make sure that schedule entries starting on
 * this day may not be assigned to the preceding day, because that would mean they would become invisible on the picasso
 * @param lastDay string description of the last day displayed. Is used to make sure that schedule entries ending on
 * this day may not be assigned to the following day, because that would mean they would become invisible on the picasso
 * @param timeBucketSize size of the time buckets into which the schedule entry boundaries are quantized, in hours
 * @returns {{bedtime: number, getUpTime: number}}
 */
export function calculateBedtime(scheduleEntries, dayjs, firstDay, lastDay, timeBucketSize = 1) {
  if (!scheduleEntries.length) return { bedtime: 24, getUpTime: 0 }

  const scheduleEntryBounds = sortBy(scheduleEntries.flatMap((scheduleEntry) => [
    { hours: toHours(dayjs.utc(scheduleEntry.start)), type: 'start' },
    { hours: toHours(dayjs.utc(scheduleEntry.end)), type: 'end' },
    // Add a copy 24 hours later, to simplify working with the circular characteristics of daytimes
    { hours: toHours(dayjs.utc(scheduleEntry.start)) + 24, type: 'start' },
    { hours: toHours(dayjs.utc(scheduleEntry.end)) + 24, type: 'end' },
  ]), (boundary) => boundary.hours)

  const gaps = scheduleEntryBounds.reduce((gaps, current, index) => {
    if (index === 0) return gaps
    const previous = scheduleEntryBounds[index - 1]
    const duration = current.hours - previous.hours
    if (duration === 0) return gaps
    gaps.push({
      start: previous.hours,
      end: current.hours,
      duration,
    })
    return gaps
  }, [])

  // The first and last day on our picasso impose some constraints on the range of bedtimes we can choose.
  const { earliestBedtime, latestGetUpTime } =
    bedtimeConstraintsFromFirstAndLastDay(scheduleEntries, firstDay, lastDay, dayjs, timeBucketSize)

  const largestBedtimeGap = maxBy(gaps.filter((gap) => {
    // Prevent bedtimes which would hide some schedule entry on the first or last day
    if (gap.start < earliestBedtime || gap.end > latestGetUpTime) return false
    // Prevent bedtimes which are not during the night
    if (gap.start > 30 || gap.end < 24) return false
    return true
  }), (gap) => gap.duration)

  return {
    bedtime: optimalQuantizedBedtime(largestBedtimeGap, scheduleEntryBounds, timeBucketSize),
    getUpTime: optimalQuantizedGetUpTime(largestBedtimeGap, scheduleEntryBounds, timeBucketSize) - 24
  }
}

function bedtimeConstraintsFromFirstAndLastDay(scheduleEntries, firstDay, lastDay, dayjs, timeBucketSize) {
  // The start of the very first schedule entry on the first day (if any) must always be displayed on the first day.
  // We must make sure that our calculated "get up time" lies before this, so we do not accidentally hide a
  // schedule entry.
  const latestGetUpTime = earliestScheduleEntryStartOnFirstDay(scheduleEntries, firstDay, dayjs, timeBucketSize)

  // Similarly, the end of the very last schedule entry end must always be displayed on the last day.
  // So we must make sure that our calculated "go to bed time" lies after this.
  const earliestBedtime = latestScheduleEntryEndOnLastDay(scheduleEntries, lastDay, dayjs, timeBucketSize)

  return {
    earliestBedtime: earliestBedtime === null ? 0 : earliestBedtime,
    latestGetUpTime: latestGetUpTime === null ? 36 : latestGetUpTime + 24
  }
}

function earliestScheduleEntryStartOnFirstDay(scheduleEntries, firstDay, dayjs, timeBucketSize) {
  const firstScheduleEntry = minBy(scheduleEntries, (scheduleEntry) => dayjs.utc(scheduleEntry.start).unix())
  if (!isOnDay(firstScheduleEntry.start, firstDay, dayjs)) return null
  return toHours(dayjs.utc(firstScheduleEntry.start))
}

function latestScheduleEntryEndOnLastDay(scheduleEntries, lastDay, dayjs, timeBucketSize) {
  const lastScheduleEntry = maxBy(scheduleEntries, (scheduleEntry) => dayjs.utc(scheduleEntry.end).unix())
  if (!isOnDay(lastScheduleEntry.end, lastDay, dayjs)) return null
  return toHours(dayjs.utc(lastScheduleEntry.end))
}

function isOnDay(scheduleEntryTime, dayStart, dayjs) {
  return dayjs.utc(scheduleEntryTime).format('YYYY-MM-DD') === dayjs.utc(dayStart).format('YYYY-MM-DD')
}

function optimalQuantizedBedtime(gap, scheduleEntryBounds, timeBucketSize) {
  const bedtime = Math.ceil(gap.start / timeBucketSize) * timeBucketSize
  if (!equals(bedtime, gap.start)) {
    // If the rounding already provides a margin, we are done
    return bedtime
  }
  if (scheduleEntryBounds.some((bound) => bound.type === 'start' && equals(bound.hours, bedtime))) {
    // If the rounding doesn't create a margin, and there exists a schedule entry starting at the bedtime,
    // we need to push the bedtime a little later
    return bedtime + timeBucketSize
  }
  // If there is no schedule entry starting at the bedtime (i.e. only schedule entries ending then),
  // we can safely cut off at that exact point
  return bedtime
}

function optimalQuantizedGetUpTime(gap, scheduleEntryBounds, timeBucketSize) {
  const getUpTime = Math.floor(gap.end / timeBucketSize) * timeBucketSize
  if (!equals(getUpTime, gap.end)) {
    // If the rounding already provides a margin, we are done
    return getUpTime
  }
  if (scheduleEntryBounds.some((bound) => bound.type === 'end' && equals(bound.hours, getUpTime))) {
    // If the rounding doesn't create a margin, and there exists a schedule entry ending at the getUpTime,
    // we need to push the getUpTime a little earlier
    return getUpTime - timeBucketSize
  }
  // If there is no schedule entry starting at the getUpTime (i.e. only schedule entries ending then),
  // we can safely cut off at that exact point
  return getUpTime
}

/**
 * Float equality comparison
 */
function equals(number1, number2) {
  return Math.abs(number1 - number2) < Number.EPSILON
}

function toHours(dateTime) {
  return dateTime.hour() + dateTime.minute() / 60
}
