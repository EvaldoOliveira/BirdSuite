"""Is it full daylight at the station? For the species that only sing at night (owner 2026-10-30, US-131)."""
import datetime

from suntime import Sun, SunTimeException

_CACHE = {}


def _minute_of_day(moment):
    local = moment.astimezone()
    return local.hour * 60 + local.minute


def daylight_window(day, lat, lon, dawn=30, dusk=30):
    """(first, last) minute of the local day counted as day: sunrise + dawn minutes and sunset - dusk minutes, kept for the
    twilight; None when the sun does not rise or set there."""
    key = (day, lat, lon, dawn, dusk)
    if key not in _CACHE:
        _CACHE.clear()  # one day and place at a time
        sun, noon = Sun(lat, lon), datetime.datetime(day.year, day.month, day.day, 12, tzinfo=datetime.timezone.utc)
        try:
            _CACHE[key] = (_minute_of_day(sun.get_sunrise_time(noon, datetime.timezone.utc)) + dawn,
                           _minute_of_day(sun.get_sunset_time(noon, datetime.timezone.utc)) - dusk)
        except SunTimeException:
            _CACHE[key] = None
    return _CACHE[key]


def is_daylight(moment, lat, lon, dawn=30, dusk=30):
    """moment: naive local time of the detection. No sunrise or sunset that day = never daylight (nothing is dropped)."""
    window = daylight_window(moment.date(), lat, lon, dawn, dusk)
    return window is not None and window[0] <= moment.hour * 60 + moment.minute <= window[1]
