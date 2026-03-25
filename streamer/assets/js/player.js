/**
 *
 * @param {Object} record
 * @returns {Promise<void>}
 */
async function recordPlayer(record) {
    const response = await fetch('/w/internal/player/activity',{
        method: 'POST',
        body: JSON.stringify(record)
    });
    return await response.json();
}

async function getRecord(params) {
    const response = await fetch("/w/internal/player/activity/record",{
        method: 'POST',
        body: JSON.stringify(params)
    });
    return await response.json();
}

/**
 *
 * @param {Event} event
 * @param {Object} video
 * @param {number} timePlayed
 */
async function getRecordParams(event, video, timePlayed) {
    return {
        event: event.type,
        video_id: video.path,
        duration: video.metadata.duration,
        current_time_played: timePlayed,
        user_agent: navigator.userAgent,
        ip_address: await getUserIP()
    }
}

async function getUserIP() {
    try {
        const response = await fetch("https://api.ipify.org?format=json");
        const data = await response.json();
        return data.ip;
    } catch (error) {
        console.error("Failed to fetch IP:", error);
        return null;
    }
}