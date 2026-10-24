import { Composition, Still } from "remotion";
import { PazarReels, PazarCover, DURATION_REELS } from "./Reels";
import { PazarReelsV2, PazarCoverV2, DURATION_V2 } from "./ReelsV2";
import { PazarReelsV3, DURATION_V3 } from "./ReelsV3";

const FPS = 30;

export const RemotionRoot: React.FC = () => {
  return (
    <>
      <Composition id="PazarReelsTRv3" component={PazarReelsV3} durationInFrames={Math.round(DURATION_V3 * FPS)} fps={FPS} width={1080} height={1920} />
      <Composition id="PazarKareTRv3" component={PazarReelsV3} durationInFrames={Math.round(DURATION_V3 * FPS)} fps={FPS} width={1080} height={1080} />
      <Composition id="PazarReelsTRv2" component={PazarReelsV2} durationInFrames={Math.round(DURATION_V2 * FPS)} fps={FPS} width={1080} height={1920} />
      <Composition id="PazarKareTRv2" component={PazarReelsV2} durationInFrames={Math.round(DURATION_V2 * FPS)} fps={FPS} width={1080} height={1080} />
      <Still id="PazarKapakTRv2" component={PazarCoverV2} width={1080} height={1920} />
      <Composition id="PazarReelsTR" component={PazarReels} durationInFrames={Math.round(DURATION_REELS * FPS)} fps={FPS} width={1080} height={1920} />
      <Still id="PazarKapakTR" component={PazarCover} width={1080} height={1920} />
    </>
  );
};
