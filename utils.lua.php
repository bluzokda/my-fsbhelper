

local imgui    = require('mimgui')
local encoding = require('encoding')
encoding.default = 'CP1251'
local u8  = encoding.UTF8




local function bringFloatTo(current, target, speed)
   local delta = math.min(imgui.GetIO().DeltaTime, 0.033) 
   local diff = target - current

   if math.abs(diff) < 0.001 then
      return target
   end

   local next_value = current + diff * speed * delta * 60

   if (diff > 0 and next_value > target) or (diff < 0 and next_value < target) then
      return target
   end

   return next_value
end




local function copyToClipboard(text)
   setClipboardText(text)
   sampAddChatMessage('[FSBHELPER] Скопировано: ' .. text, 0x00FF00)
end




local lower_map = { [string.char(168)] = string.char(184) }
for i = 192, 223 do
   lower_map[string.char(i)] = string.char(i + 32)
end

local orig_lower = string.lower

local function lower_cp1251(str)
   if type(str) ~= 'string' then return str end

   local result = (str:gsub(".", lower_map))
   return orig_lower(result)
end




local function safe_u8(text)
   if text == nil then
      return u8''
   end

   if type(text) ~= 'string' then
      text = tostring(text)
   end

   return u8(text)
end

return {
   bringFloatTo    = bringFloatTo,
   copyToClipboard = copyToClipboard,
   lower_cp1251    = lower_cp1251,
   safe_u8         = safe_u8,
}
