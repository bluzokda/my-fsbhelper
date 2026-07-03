



local imgui    = require('mimgui')
local encoding = require('encoding')
encoding.default = 'CP1251'
local u8  = encoding.UTF8
local bit = require('bit')
local COLORS   = require('lib.fsbhelper.constants').COLORS

local M = {}




function string.levenshtein(str1, str2)
    local len1, len2 = #str1, #str2
    if len1 == 0 then return len2 end
    if len2 == 0 then return len1 end
    
    local v0, v1 = {}, {}
    for i = 0, len2 do v0[i] = i end
    
    for i = 1, len1 do
        v1[0] = i
        for j = 1, len2 do
            local cost = (str1:byte(i) == str2:byte(j)) and 0 or 1
            v1[j] = math.min(v1[j-1] + 1, v0[j] + 1, v0[j-1] + cost)
        end
        for j = 0, len2 do v0[j] = v1[j] end
    end
    return v0[len2]
end








local deps     = {}
local fsbhelper, state, CONSTANTS
local lower_cp1251, safe_u8_fn, logError, safe_read_all

function M.init(d)
    deps         = d
    fsbhelper        = d.fsbhelper
    state        = d.state
    CONSTANTS    = d.CONSTANTS
    lower_cp1251 = d.lower_cp1251
    safe_u8_fn   = d.safe_u8
    logError     = d.logError
    safe_read_all = d.safe_read_all
end




local function safe_u8(s) return safe_u8_fn(s) end




local function withFont(font, func)
    if font then imgui.PushFont(font) end
    local ok, err = pcall(func)
    if font then imgui.PopFont() end
    if not ok and logError then logError('[withFont] ' .. tostring(err)) end
end




local search_synonyms = {
    ["тонировка"]           = {"тонировочная", "затемнение", "тонирование"},
    ["полиция"]             = {"мвд", "полицейский", "правоохранитель"},
    ["обыск"]               = {"досмотр", "проверка", "обыскивание"},
    ["задержание"]          = {"арест", "задержать", "задержали"},
    ["радар"]               = {"радары", "радаром", "скорость"},
    ["маска"]               = {"маски", "снятие", "замаскированный"},
    ["удостоверение"]       = {"документ", "удостоверения", "корочка"},
    ["закрытая территория"] = {"зт", "закрытые территории", "охраняемая зона"},
    ["автомобиль"]          = {"машина", "транспорт", "авто", "тс"},
    ["оружие"]              = {"пушка", "ствол", "огнестрел", "травмат"}
}




local function processSpecialTags(text)
    if not text then return text end

    text = text:gsub("{time}", function()
        local utc = os.time(os.date("!*t"))
        local msk = utc + 3 * 3600
        local t = os.date("*t", msk)
        return string.format("%02d:%02d", t.hour, t.min)
    end)

    return text
end
M.processSpecialTags = processSpecialTags




local function get_middle_button_x(count)
    local width = imgui.GetContentRegionAvail().x
    local space = imgui.GetStyle().ItemSpacing.x
    return count == 1 and width or width / count - ((space * (count - 1)) / count)
end
M.get_middle_button_x = get_middle_button_x




local function renderTabBar(labels, int_ptr, cols_per_row, id_prefix)
    local button_width = get_middle_button_x(cols_per_row or #labels)
    local current_tab = int_ptr[0] or 0
    for i = 0, #labels - 1 do
        if i > 0 and cols_per_row and i % cols_per_row == 0 then imgui.Spacing() end
        if i > 0 and (not cols_per_row or i % cols_per_row ~= 0) then imgui.SameLine() end
        if current_tab == i then
            imgui.PushStyleColor(imgui.Col.Button,        COLORS.accent)
            imgui.PushStyleColor(imgui.Col.ButtonHovered, COLORS.btn_hov)
            imgui.PushStyleColor(imgui.Col.ButtonActive,  COLORS.btn_act)
        end
        if imgui.Button(labels[i+1] .. '##' .. (id_prefix or 'tab') .. '_' .. i, imgui.ImVec2(button_width, 37)) then
            int_ptr[0] = i
        end
        if current_tab == i then imgui.PopStyleColor(3) end
    end
end
M.renderTabBar = renderTabBar




local color_cache = {}
local function getColorFromHex(hex)
    if not color_cache[hex] then
        local n = tonumber(hex, 16)
        if n then
            color_cache[hex] = imgui.ImVec4(
                bit.rshift(bit.band(n, 0xFF0000), 16) / 255.0,
                bit.rshift(bit.band(n, 0x00FF00),  8) / 255.0,
                bit.band(n, 0x0000FF)                / 255.0,
                1.0
            )
        end
    end
    return color_cache[hex]
end
M.getColorFromHex = getColorFromHex




local function renderFormattedText(text)
    local default_color_vec = COLORS.text_primary
    local line_height   = imgui.GetTextLineHeight()
    local space_width   = imgui.CalcTextSize(' ').x
    local window_pos    = imgui.GetCursorScreenPos()
    local line_width    = imgui.GetWindowContentRegionMax().x - imgui.GetStyle().WindowPadding.x
    local current_pos   = imgui.ImVec2(window_pos.x, window_pos.y)
    local line_spacing  = (fsbhelper.config.lineSpacing or 0.0) * line_height

    local lines = {}
    for line in text:gmatch("[^\r\n]*") do table.insert(lines, line) end

    for i, line in ipairs(lines) do
        if #line == 0 then
            current_pos.y = current_pos.y + line_height * 0.3 + line_spacing
            goto continue
        end

        current_pos.x = window_pos.x
        local segments = {}
        local current_color = default_color_vec
        local last_pos = 1

        while true do
            local s, e, _, hex = line:find("({(%x%x%x%x%x%x)})", last_pos)
            if not s then
                local remaining_text = line:sub(last_pos)
                if #remaining_text > 0 then table.insert(segments, {text = remaining_text, color = current_color}) end
                break
            end

            local pretext = line:sub(last_pos, s - 1)
            if #pretext > 0 then table.insert(segments, {text = pretext, color = current_color}) end

            current_color = getColorFromHex(hex)
            last_pos = e + 1
        end

        for _, segment in ipairs(segments) do
            local words = {}
            for word in segment.text:gmatch("%S+") do table.insert(words, word) end

            for _, word in ipairs(words) do
                local word_u8    = safe_u8(word)
                local word_width = imgui.CalcTextSize(word_u8).x

                if current_pos.x > window_pos.x and current_pos.x + word_width > window_pos.x + line_width then
                    current_pos.x = window_pos.x
                    current_pos.y = current_pos.y + line_height
                end

                imgui.SetCursorScreenPos(current_pos)
                imgui.TextColored(segment.color, word_u8)
                current_pos.x = current_pos.x + word_width + space_width
            end
        end

        if i < #lines then
            current_pos.y = current_pos.y + line_height + line_spacing
        end

        ::continue::
    end
    imgui.SetCursorScreenPos(imgui.ImVec2(window_pos.x, current_pos.y))
end
M.renderFormattedText = renderFormattedText




local function clearTextCache()
    fsbhelper.text_cache    = {}
    fsbhelper.text_cache_ts = {}
end
M.clearTextCache = clearTextCache




local function loadTextFromFile(filename)
    local now       = os.time()
    local cached    = fsbhelper.text_cache[filename]
    local cached_ts = fsbhelper.text_cache_ts[filename]
    if cached and cached_ts and (now - cached_ts) < CONSTANTS.LIMITS.CACHE_TTL then
        return cached
    end

    local filepath = fsbhelper.TEXTS_DIR .. '\\' .. filename

    if not doesFileExist(filepath) then
        return "{FF0000}Ошибка: текст не найден.\n{FFFFFF}Проверьте наличие файла " .. filename .. " в папке texts/"
    end

    local content, read_err = safe_read_all(filepath, 'rb', false)
    if not content then
        logError('Ошибка открытия текстового файла: ' .. tostring(filepath) .. ', error: ' .. tostring(read_err))
        return "{FF0000}Ошибка: не удалось открыть файл " .. filename
    end

    if content:sub(1, 3) == '\xEF\xBB\xBF' then
        
        content = content:sub(4)
        local decode_success, decoded = pcall(encoding.UTF8.decode, encoding.UTF8, content)
        if decode_success then content = decoded end
    else
        
        
        
        local ok, decoded = pcall(encoding.UTF8.decode, encoding.UTF8, content)
        if ok and decoded then content = decoded end
        
    end

    fsbhelper.text_cache[filename]    = content
    fsbhelper.text_cache_ts[filename] = now
    return content
end
M.loadTextFromFile = loadTextFromFile




local function searchInText(text, query)
    if not query or #query == 0 then return {}, {} end

    local query_clean = query:gsub("^%s*(.-)%s*$", "%1")
    if #query_clean == 0 then return {}, {} end

    local decode_success, query_cp1251 = pcall(encoding.UTF8.decode, encoding.UTF8, query_clean)
    if not decode_success then query_cp1251 = query_clean end
    local query_lower = lower_cp1251(query_cp1251)

    local exclude_words = {}
    local search_query  = query_lower

    for word in query_cp1251:gmatch("%-(%S+)") do
        exclude_words[word] = true
        search_query = search_query:gsub("%-%S+", "")
    end

    search_query = search_query:gsub("^%s*(.-)%s*$", "%1")
    if #search_query == 0 then return {}, {} end

    local results = {}
    local lines   = {}

    for line in text:gmatch("[^\r\n]+") do
        table.insert(lines, line)
    end

    local search_variants = {}
    table.insert(search_variants, {pattern = search_query, boost = 5000, type = "exact"})

    for original, synonyms in pairs(search_synonyms) do
        if search_query:find(original, 1, true) then
            for _, synonym in ipairs(synonyms) do
                table.insert(search_variants, {pattern = synonym, boost = 4000, type = "synonym"})
            end
        end
    end

    local query_words = {}
    for word in search_query:gmatch("%S+") do
        table.insert(query_words, word)
    end

    for line_num, line in ipairs(lines) do
        local clean_line = line:gsub("{%x%x%x%x%x%x}", "")
        local lower_line = lower_cp1251(clean_line)

        local quick_match = lower_line:find(search_query, 1, true)

        local has_excluded = false
        for exclude_word, _ in pairs(exclude_words) do
            if lower_line:find(exclude_word, 1, true) then
                has_excluded = true
                break
            end
        end

        if not has_excluded then
            local max_relevance = 0
            local best_match    = nil

            if quick_match then
                local relevance = 5000 + (1000 - quick_match) + math.max(0, 500 - #clean_line)
                if quick_match == 1 or lower_line:sub(quick_match - 1, quick_match - 1):match("%s") then
                    relevance = relevance + 1000
                end
                max_relevance = relevance
                best_match = {variant = "exact", position = quick_match, word = search_query}
            end

            for _, variant in ipairs(search_variants) do
                local pos = lower_line:find(variant.pattern, 1, true)
                if pos then
                    local relevance = variant.boost + (1000 - pos) + math.max(0, 500 - #clean_line)
                    if pos == 1 or lower_line:sub(pos - 1, pos - 1):match("%s") then relevance = relevance + 1000 end

                    if relevance > max_relevance then
                        max_relevance = relevance
                        best_match = {variant = variant.type, position = pos, word = variant.pattern}
                    end
                end
            end

            if max_relevance == 0 and #query_words > 0 then
                for line_word in lower_line:gmatch("%S+") do
                    if #line_word > 3 then
                        for _, q_word in ipairs(query_words) do
                            if #q_word > 3 then
                                local dist = string.levenshtein(q_word, line_word)
                                local allowed_errors = math.min(2, math.floor(#q_word * 0.3))

                                if dist <= allowed_errors then
                                    local relevance = 2500 - (dist * 500)
                                    if relevance > max_relevance then
                                        max_relevance = relevance
                                        local pos = lower_line:find(line_word, 1, true) or 1
                                        best_match = {variant = "fuzzy", position = pos, word = line_word}
                                    end
                                end
                            end
                        end
                    end
                end
            end

            if max_relevance > 0 and best_match then
                table.insert(results, {
                    line_num   = line_num,
                    line       = line,
                    clean_line = clean_line,
                    relevance  = max_relevance,
                    position   = best_match.position,
                    match_word = best_match.word
                })
            end
        end
    end

    table.sort(results, function(a, b) return a.relevance > b.relevance end)
    return results, lines
end




local cache_search = {
    query = "", rule_index = nil, tab_index = nil,
    full_text = nil, results = nil, lines = nil
}

local function searchInTextCached(full_text, query, rule_index, tab_index)
    local query_str = query or ""

    if cache_search.query      == query_str
    and cache_search.rule_index == rule_index
    and cache_search.tab_index  == tab_index
    and cache_search.full_text  == full_text then
        return cache_search.results, cache_search.lines
    end

    local results, lines = searchInText(full_text, query)

    cache_search.query      = query_str
    cache_search.rule_index = rule_index
    cache_search.tab_index  = tab_index
    cache_search.full_text  = full_text
    cache_search.results    = results
    cache_search.lines      = lines

    return results, lines
end
M.searchInTextCached = searchInTextCached




local function renderHighlightedText(text, match_word)
    if not match_word or match_word == "" then
        imgui.TextWrapped(safe_u8(text))
        return
    end

    local text_lower = lower_cp1251(text)
    local s, e = text_lower:find(match_word, 1, true)

    if s then
        if s > 1 then
            local part1 = text:sub(1, s - 1)
            imgui.TextColored(COLORS.text_body, safe_u8(part1))
            imgui.SameLine(nil, 0)
        end

        local part2 = text:sub(s, e)
        imgui.TextColored(COLORS.gold, safe_u8(part2))

        if e < #text then
            imgui.SameLine(nil, 0)
            local part3 = text:sub(e + 1)
            imgui.TextColored(COLORS.text_body, safe_u8(part3))
        end
    else
        imgui.TextWrapped(safe_u8(text))
    end
end
M.renderHighlightedText = renderHighlightedText




local function renderSearchResults(full_text, query, rule_index, tab_index)
    if not query or #query == 0 then
        imgui.PushTextWrapPos(0)
        renderFormattedText(full_text)
        imgui.PopTextWrapPos()
        return
    end

    local results, all_lines = searchInTextCached(full_text, query, rule_index or 0, tab_index or 0)

    if #results == 0 then
        imgui.TextColored(COLORS.error_soft, u8'Ничего не найдено')
        imgui.Spacing()
        imgui.TextColored(COLORS.text_label, u8'Попробуйте:')
        imgui.BulletText(u8'Использовать синонимы')
        imgui.BulletText(u8'Проверить опечатки (авто-поиск исправляет только мелкие)')
        imgui.Spacing()
        imgui.Separator()
        imgui.Spacing()
        imgui.PushTextWrapPos(0)
        renderFormattedText(full_text)
        imgui.PopTextWrapPos()
        return
    end

    imgui.TextColored(COLORS.success, u8(string.format('Найдено: %d', #results)))
    imgui.Spacing()
    imgui.Separator()
    imgui.Spacing()

    local shown_lines  = {}
    local context_size = 1

    for i, result in ipairs(results) do
        if i > CONSTANTS.LIMITS.MAX_SEARCH_RESULTS then break end

        local start_line = math.max(1, result.line_num - context_size)
        local end_line   = math.min(#all_lines, result.line_num + context_size)

        local already_shown = false
        for shown_start, shown_end in pairs(shown_lines) do
            if result.line_num >= shown_start and result.line_num <= shown_end then
                already_shown = true
                break
            end
        end

        if not already_shown then
            shown_lines[start_line] = end_line

            imgui.PushStyleColor(imgui.Col.Text, COLORS.text_info)
            imgui.Text(u8(string.format('Результат #%d', i)))
            imgui.PopStyleColor()

            for line_idx = start_line, end_line do
                if line_idx == result.line_num then
                    imgui.TextColored(COLORS.marker_green, u8"> ")
                    imgui.SameLine()
                    imgui.PushTextWrapPos(imgui.GetContentRegionAvail().x)
                    renderHighlightedText(result.clean_line, result.match_word)
                    imgui.PopTextWrapPos()
                else
                    imgui.PushTextWrapPos(imgui.GetContentRegionAvail().x)
                    imgui.TextColored(COLORS.text_hint,
                        u8("  " .. all_lines[line_idx]:gsub("{%x%x%x%x%x%x}", "")))
                    imgui.PopTextWrapPos()
                end
            end

            imgui.Spacing()
            imgui.Separator()
            imgui.Spacing()
        end
    end
end
M.renderSearchResults = renderSearchResults




local RULE_FILES = {
    police    = {"police_main.txt", "police_radar.txt", "police_mask.txt", "police_tint.txt"},
    legal     = {"legal_constitution.txt", "legal_federal.txt", "legal_uk.txt", "legal_koap.txt", "legal_police_law.txt", "legal_fsb_law.txt"},
    territory = {"territory_main.txt", "territory_mvd.txt", "territory_fsb.txt", "territory_army.txt", "territory_fsin.txt", "territory_mchs.txt", "territory_hospital.txt", "territory_smi.txt", "territory_government.txt"}
}

local function getRuleText(category, tabIndex)
    local files = RULE_FILES[category]
    local idx = (tonumber(tabIndex) or 0) + 1

    if not files then return "{FF0000}Ошибка: неизвестная категория правил" end
    if idx < 1 then idx = 1 elseif idx > #files then idx = #files end

    local filename = files[idx]
    if not filename then return "{FF0000}Ошибка: файл не найден" end

    return loadTextFromFile(filename)
end
M.getRuleText = getRuleText

local function getTerritoryArmySupplementText() return loadTextFromFile("territory_army_supplement.txt") end
local function getTerritoryFsinSupplementText()  return loadTextFromFile("territory_fsin_supplement.txt") end
local function getHierarchyText()                return loadTextFromFile("hierarchy.txt") end
local function getUPKText()                      return loadTextFromFile("upk.txt") end
local function getJudicialSystemText()           return loadTextFromFile("legal_judicial_system.txt") end
local function getJusticeText()                  return loadTextFromFile("legal_justice.txt") end
local function getTransferSystemText()           return loadTextFromFile("legal_transfer_system.txt") end
local function getRegInspectionText()            return loadTextFromFile("reg_inspection.txt") end
local function getRegFactionCriteriaText()       return loadTextFromFile("reg_faction_criteria.txt") end
local function getRegAppearanceText()            return loadTextFromFile("reg_appearance.txt") end
local function getRegStructureText()             return loadTextFromFile("reg_structure.txt") end
local function getRegElectionsText()             return loadTextFromFile("reg_elections.txt") end
local function getRegScheduleText()              return loadTextFromFile("reg_schedule.txt") end
local function getRegStateWaveText()             return loadTextFromFile("reg_state_wave.txt") end
local function getFsbStatuteText()               return loadTextFromFile("fsb_statute.txt") end
local function getFsbInfiltrationText()          return loadTextFromFile("fsb_infiltration.txt") end
local function getLaborCodeText()                return loadTextFromFile("labor_code.txt") end
local function getMVDHandbookText()              return loadTextFromFile("mvd_handbook.txt") end
local function getMVDDrillRegulationsText()      return loadTextFromFile("mvd_drill.txt") end
local function getMVDStatuteText()               return loadTextFromFile("mvd_statute.txt") end

M.getTerritoryArmySupplementText = getTerritoryArmySupplementText
M.getTerritoryFsinSupplementText  = getTerritoryFsinSupplementText
M.getHierarchyText               = getHierarchyText
M.getUPKText                     = getUPKText
M.getJudicialSystemText          = getJudicialSystemText
M.getJusticeText                 = getJusticeText
M.getTransferSystemText          = getTransferSystemText
M.getRegInspectionText           = getRegInspectionText
M.getRegFactionCriteriaText      = getRegFactionCriteriaText
M.getRegAppearanceText           = getRegAppearanceText
M.getRegStructureText            = getRegStructureText
M.getRegElectionsText            = getRegElectionsText
M.getRegScheduleText             = getRegScheduleText
M.getRegStateWaveText            = getRegStateWaveText
M.getFsbStatuteText              = getFsbStatuteText
M.getFsbInfiltrationText         = getFsbInfiltrationText
M.getLaborCodeText               = getLaborCodeText
M.getMVDHandbookText             = getMVDHandbookText
M.getMVDDrillRegulationsText     = getMVDDrillRegulationsText
M.getMVDStatuteText              = getMVDStatuteText




local function initStaticRules()
    fsbhelper.rulesDB = {
        
        {name = "Законодательная база",                                              category = "law", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "ФЗ \"О судебной системе и статусе судей\"",                        category = "law", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "ФЗ \"О закрытых и охраняемых территориях\"",                       category = "law", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "ФЗ \"О системе нормативно-правовых актов Нижегородской области\"", category = "law", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "Уголовно-процессуальный кодекс",                                   category = "law", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "ФЗ \"О правосудии\"",                                              category = "law", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "Трудовой кодекс",                                                  category = "law", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "Единая система переводов",                                          category = "law", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        
        {name = "Правила для полицейских",                                           category = "reg", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "Регламент проведения проверок",                                     category = "reg", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "Критерии для вступления во фракции",                               category = "reg", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "Уставной внешний вид",                                             category = "reg", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "Структура государственных фракций",                                category = "reg", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "Система выборов губернатора",                                       category = "reg", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "График рабочего дня",                                              category = "reg", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "Правила государственной волны",                                    category = "reg", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        
        {name = "ФСБ | Устав",                                                      category = "fsb", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "ФСБ | Правила внедрения",                                          category = "fsb", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        
        {name = "МВД | Справочник Сотрудника",                                      category = "mvd", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "МВД | Правила строевого устава",                                   category = "mvd", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
        {name = "МВД | Устав",                                                      category = "mvd", updateDate = "30.04.2026", key = {}, keyName = "Не назначено", holdMode = false, extra_h = -9},
    }
end
M.initStaticRules = initStaticRules

return M

